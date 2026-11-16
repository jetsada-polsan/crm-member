<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('erp_customers', 'route_code')) {
                $table->string('route_code', 32)->nullable()->after('province');
            }
            if (! Schema::hasColumn('erp_customers', 'route_name')) {
                $table->string('route_name', 120)->nullable()->after('route_code');
            }
            if (! Schema::hasColumn('erp_customers', 'credit_limit')) {
                $table->decimal('credit_limit', 16, 2)->nullable()->after('route_name');
            }
            if (! Schema::hasColumn('erp_customers', 'credit_term_days')) {
                $table->integer('credit_term_days')->nullable()->after('credit_limit');
            }
            if (! Schema::hasColumn('erp_customers', 'tax_id')) {
                $table->string('tax_id', 32)->nullable()->after('credit_term_days');
            }
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('erp_products', 'category_code')) {
                $table->string('category_code', 32)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('erp_products', 'category_name')) {
                $table->string('category_name', 120)->nullable()->after('category_code');
            }
            if (! Schema::hasColumn('erp_products', 'is_excluded_kpi')) {
                $table->boolean('is_excluded_kpi')->default(false)->after('category_name');
            }
        });

        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('erp_sales_invoices', 'dt_properties')) {
                $table->integer('dt_properties')->nullable()->after('doc_type');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'doc_category')) {
                $table->string('doc_category', 24)->default('sale')->after('dt_properties');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'before_vat_amount')) {
                $table->decimal('before_vat_amount', 16, 2)->nullable()->after('net_amount');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'vat_amount')) {
                $table->decimal('vat_amount', 16, 2)->nullable()->after('before_vat_amount');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'gross_amount')) {
                $table->decimal('gross_amount', 16, 2)->nullable()->after('vat_amount');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'salesperson_code')) {
                $table->string('salesperson_code', 32)->nullable()->after('erp_customer_code');
            }
            if (! Schema::hasColumn('erp_sales_invoices', 'salesperson_name')) {
                $table->string('salesperson_name', 120)->nullable()->after('salesperson_code');
            }

            $table->index(['doc_category', 'status', 'sold_at'], 'erp_invoices_category_status_sold_idx');
            $table->index('dt_properties', 'erp_invoices_dt_properties_idx');
            $table->index('salesperson_code', 'erp_invoices_salesperson_code_idx');
        });

        Schema::table('erp_sales_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('erp_sales_items', 'iccat_code')) {
                $table->string('iccat_code', 32)->nullable()->after('product_name');
            }
            if (! Schema::hasColumn('erp_sales_items', 'iccat_name')) {
                $table->string('iccat_name', 120)->nullable()->after('iccat_code');
            }
            if (! Schema::hasColumn('erp_sales_items', 'is_excluded_kpi')) {
                $table->boolean('is_excluded_kpi')->default(false)->after('iccat_name');
            }
            if (! Schema::hasColumn('erp_sales_items', 'before_vat_amount')) {
                $table->decimal('before_vat_amount', 16, 2)->nullable()->after('net_amount');
            }
            if (! Schema::hasColumn('erp_sales_items', 'vat_amount')) {
                $table->decimal('vat_amount', 16, 2)->nullable()->after('before_vat_amount');
            }
            if (! Schema::hasColumn('erp_sales_items', 'gross_amount')) {
                $table->decimal('gross_amount', 16, 2)->nullable()->after('vat_amount');
            }
            if (! Schema::hasColumn('erp_sales_items', 'refer_trd_key')) {
                $table->string('refer_trd_key', 64)->nullable()->after('gross_amount');
            }
            if (! Schema::hasColumn('erp_sales_items', 'refer_doc_ref')) {
                $table->string('refer_doc_ref', 80)->nullable()->after('refer_trd_key');
            }

            $table->index('is_excluded_kpi', 'erp_items_excluded_kpi_idx');
            $table->index('iccat_code', 'erp_items_iccat_code_idx');
        });

        if (! Schema::hasTable('erp_salespersons')) {
            Schema::create('erp_salespersons', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 32)->default('BPLUS');
                $table->string('salesperson_code', 32);
                $table->string('salesperson_name', 120);
                $table->boolean('is_active')->default(true);
                $table->string('line_code', 32)->nullable();
                $table->string('line_name', 120)->nullable();
                $table->string('team_code', 32)->nullable();
                $table->string('team_name', 120)->nullable();
                $table->string('area_code', 32)->nullable();
                $table->string('area_name', 120)->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->unique(['source_system', 'salesperson_code'], 'erp_salespersons_source_code_unique');
            });
        }

        if (! Schema::hasTable('erp_members')) {
            Schema::create('erp_members', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 32)->default('BPLUS');
                $table->string('member_code', 64);
                $table->string('title', 40)->nullable();
                $table->string('full_name', 255);
                $table->string('phone', 64)->nullable();
                $table->string('mobile_phone', 64)->nullable();
                $table->string('email', 180)->nullable();
                $table->text('address')->nullable();
                $table->string('member_type_code', 32)->nullable();
                $table->string('member_type_name', 120)->nullable();
                $table->timestampTz('member_since')->nullable();
                $table->timestampTz('member_expire')->nullable();
                $table->integer('erp_point_balance')->default(0);
                $table->integer('erp_redeemed_total')->default(0);
                $table->decimal('erp_spent_total', 16, 2)->default(0);
                $table->timestampTz('last_point_at')->nullable();
                $table->timestamps();

                $table->unique(['source_system', 'member_code'], 'erp_members_source_code_unique');
            });
        }

        Schema::table('member_erp_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('member_erp_links', 'verification_status')) {
                $table->string('verification_status', 24)->default('pending')->after('verified_at');
            }
            if (! Schema::hasColumn('member_erp_links', 'verified_by')) {
                $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            }
            if (! Schema::hasColumn('member_erp_links', 'verification_note')) {
                $table->text('verification_note')->nullable()->after('verified_by');
            }
            if (! Schema::hasColumn('member_erp_links', 'erp_member_code')) {
                $table->string('erp_member_code', 64)->nullable()->after('verification_note');
            }
        });

        DB::table('member_erp_links')
            ->whereNotNull('verified_at')
            ->where('verification_status', 'pending')
            ->update(['verification_status' => 'verified']);
    }

    public function down(): void
    {
        Schema::table('member_erp_links', function (Blueprint $table): void {
            foreach (['erp_member_code', 'verification_note', 'verified_by', 'verification_status'] as $col) {
                if (Schema::hasColumn('member_erp_links', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('erp_members');
        Schema::dropIfExists('erp_salespersons');

        Schema::table('erp_sales_items', function (Blueprint $table): void {
            $table->dropIndex('erp_items_excluded_kpi_idx');
            $table->dropIndex('erp_items_iccat_code_idx');
            foreach (['refer_doc_ref', 'refer_trd_key', 'gross_amount', 'vat_amount', 'before_vat_amount', 'is_excluded_kpi', 'iccat_name', 'iccat_code'] as $col) {
                if (Schema::hasColumn('erp_sales_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_invoices_category_status_sold_idx');
            $table->dropIndex('erp_invoices_dt_properties_idx');
            $table->dropIndex('erp_invoices_salesperson_code_idx');
            foreach (['salesperson_name', 'salesperson_code', 'gross_amount', 'vat_amount', 'before_vat_amount', 'doc_category', 'dt_properties'] as $col) {
                if (Schema::hasColumn('erp_sales_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            foreach (['is_excluded_kpi', 'category_name', 'category_code'] as $col) {
                if (Schema::hasColumn('erp_products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('erp_customers', function (Blueprint $table): void {
            foreach (['tax_id', 'credit_term_days', 'credit_limit', 'route_name', 'route_code'] as $col) {
                if (Schema::hasColumn('erp_customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
