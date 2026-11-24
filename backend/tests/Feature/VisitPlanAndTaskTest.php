<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VisitPlanAndTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createStaff(string $role, ?string $salespersonCode = null): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff '.$role,
            'email' => $role.uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'salesperson_code' => $salespersonCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    private function customer(string $code, string $salespersonCode): int
    {
        return DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS', 'erp_customer_code' => $code, 'customer_name' => 'Shop '.$code,
            'salesperson_code' => $salespersonCode, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function plan(int $assignedTo): int
    {
        return DB::table('crm_visit_plans')->insertGetId([
            'route_name' => 'Route A', 'visit_date' => '2026-11-25', 'assigned_to' => $assignedTo,
            'created_by' => $assignedTo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sales_staff_build_their_own_plan_stop_by_stop(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $firstShop = $this->customer('AR001', 'S01');
        $secondShop = $this->customer('AR002', 'S01');
        $this->actingAs($sales, 'staff');

        $planId = $this->postJson('/api/crm/plans', ['route_name' => 'Route A', 'visit_date' => '2026-11-25'])
            ->assertCreated()
            ->assertJson(['added_stops' => 0])
            ->json('id');
        $this->postJson("/api/crm/plans/{$planId}/stops", ['erp_customer_id' => $firstShop])
            ->assertCreated()->assertJson(['stop_order' => 1]);
        $secondStopId = $this->postJson("/api/crm/plans/{$planId}/stops", ['erp_customer_id' => $secondShop])
            ->assertCreated()->assertJson(['stop_order' => 2])->json('id');

        $this->patchJson("/api/crm/plans/{$planId}/stops/{$secondStopId}", ['status' => 'completed'])->assertOk();

        $this->getJson('/api/crm/plans')
            ->assertOk()
            ->assertJsonPath('0.id', $planId)
            ->assertJsonPath('0.assigned_to', $sales->id)
            ->assertJsonPath('0.stop_count', 2)
            ->assertJsonPath('0.completed_stop_count', 1);

        $this->deleteJson("/api/crm/plans/{$planId}/stops/{$secondStopId}")->assertOk();
        $this->assertDatabaseMissing('crm_visit_plan_stops', ['id' => $secondStopId]);
    }

    public function test_plan_stop_must_name_exactly_one_customer_and_a_valid_status(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $shop = $this->customer('AR001', 'S01');
        $planId = $this->plan($sales->id);
        $this->actingAs($sales, 'staff');

        $this->postJson("/api/crm/plans/{$planId}/stops", [])->assertUnprocessable()->assertJsonValidationErrors('customer');
        $stopId = $this->postJson("/api/crm/plans/{$planId}/stops", ['erp_customer_id' => $shop])->assertCreated()->json('id');
        $this->patchJson("/api/crm/plans/{$planId}/stops/{$stopId}", ['status' => 'done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_sales_staff_cannot_plan_for_others_or_use_customers_outside_their_scope(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $colleague = $this->createStaff('sales', 'S02');
        $foreignShop = $this->customer('AR900', 'S02');
        $colleaguePlan = $this->plan($colleague->id);
        $ownPlan = $this->plan($sales->id);
        $colleagueStop = DB::table('crm_visit_plan_stops')->insertGetId([
            'plan_id' => $colleaguePlan, 'stop_order' => 1, 'erp_customer_id' => $foreignShop,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($sales, 'staff');

        $this->postJson('/api/crm/plans', ['route_name' => 'X', 'visit_date' => '2026-11-25', 'assigned_to' => $colleague->id])
            ->assertForbidden();
        $this->postJson("/api/crm/plans/{$ownPlan}/stops", ['erp_customer_id' => $foreignShop])->assertForbidden();
        $this->postJson("/api/crm/plans/{$colleaguePlan}/stops", ['erp_customer_id' => $foreignShop])->assertForbidden();
        $this->patchJson("/api/crm/plans/{$colleaguePlan}/stops/{$colleagueStop}", ['status' => 'skipped'])->assertForbidden();
        $this->deleteJson("/api/crm/plans/{$colleaguePlan}/stops/{$colleagueStop}")->assertForbidden();
        $this->postJson("/api/crm/plans/{$colleaguePlan}/auto-stops")->assertForbidden();

        $this->assertSame('pending', DB::table('crm_visit_plan_stops')->where('id', $colleagueStop)->value('status'));
        $this->getJson('/api/crm/plans')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $ownPlan);
    }

    public function test_auto_fill_adds_only_unvisited_customers_in_the_assignees_scope(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $visitedShop = $this->customer('AR001', 'S01');
        $unvisitedShop = $this->customer('AR002', 'S01');
        $this->customer('AR900', 'S02');
        DB::table('crm_customer_visits')->insert([
            'erp_customer_id' => $visitedShop, 'staff_user_id' => $sales->id, 'visited_at' => now(),
            'result' => 'visited', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->createStaff('manager'), 'staff');

        $planId = $this->postJson('/api/crm/plans', [
            'route_name' => 'Auto', 'visit_date' => '2026-11-25', 'assigned_to' => $sales->id,
            'auto_fill_mode' => 'unvisited', 'max_stops' => 10,
        ])->assertCreated()->assertJson(['added_stops' => 1])->json('id');

        $this->assertSame(
            [$unvisitedShop],
            DB::table('crm_visit_plan_stops')->where('plan_id', $planId)->pluck('erp_customer_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->postJson("/api/crm/plans/{$planId}/auto-stops", ['mode' => 'unvisited'])->assertOk()->assertJson(['added_stops' => 0]);
    }

    public function test_follow_up_tasks_are_listed_and_completed_only_by_their_owner_or_a_manager(): void
    {
        $owner = $this->createStaff('sales', 'S01');
        $other = $this->createStaff('sales', 'S02');
        $taskId = DB::table('crm_follow_up_tasks')->insertGetId([
            'assigned_to' => $owner->id, 'title' => 'Call back', 'due_date' => '2026-11-26', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($other, 'staff');
        $this->getJson('/api/crm/tasks')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/crm/tasks/{$taskId}/complete")->assertForbidden();

        $this->actingAs($owner, 'staff');
        $this->getJson('/api/crm/tasks')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $taskId);
        $this->postJson("/api/crm/tasks/{$taskId}/complete")->assertOk();
        $this->assertSame('done', DB::table('crm_follow_up_tasks')->where('id', $taskId)->value('status'));
        $this->postJson('/api/crm/tasks/999999/complete')->assertNotFound();
    }
}
