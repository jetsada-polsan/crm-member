#Requires -Version 5.1
<#
.SYNOPSIS
    Configure the local Bplus SQL connection without displaying or committing its password.
#>

$ErrorActionPreference = "Stop"
$AgentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigFile = Join-Path $AgentDir "config.env"
$Server = "192.168.88.200"
$Port = "1433"
$Database = "BPLUSERP_POPSTAR_2021"
$User = "jetsada"

if (-not (Test-Path -LiteralPath $ConfigFile)) {
    throw "sync-agent/config.env is missing; create it from config.env.example first."
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$form = New-Object System.Windows.Forms.Form
$form.Text = "Popstar — Bplus SQL configuration"
$form.StartPosition = "CenterScreen"
$form.Size = New-Object System.Drawing.Size(500, 230)
$form.FormBorderStyle = "FixedDialog"
$form.MaximizeBox = $false
$form.MinimizeBox = $false
$form.TopMost = $true

$heading = New-Object System.Windows.Forms.Label
$heading.Text = "ตั้งค่ารหัสผ่าน Bplus SQL"
$heading.Font = New-Object System.Drawing.Font("Segoe UI", 14, [System.Drawing.FontStyle]::Bold)
$heading.Location = New-Object System.Drawing.Point(24, 20)
$heading.Size = New-Object System.Drawing.Size(430, 32)
$form.Controls.Add($heading)

$detail = New-Object System.Windows.Forms.Label
$detail.Text = "Server $Server : $Port · Database $Database · User $User"
$detail.Location = New-Object System.Drawing.Point(26, 61)
$detail.Size = New-Object System.Drawing.Size(430, 24)
$form.Controls.Add($detail)

$label = New-Object System.Windows.Forms.Label
$label.Text = "Password (ซ่อนตัวอักษร และเก็บแบบ DPAPI บนเครื่องนี้)"
$label.Location = New-Object System.Drawing.Point(26, 93)
$label.Size = New-Object System.Drawing.Size(430, 22)
$form.Controls.Add($label)

$passwordBox = New-Object System.Windows.Forms.TextBox
$passwordBox.Location = New-Object System.Drawing.Point(26, 118)
$passwordBox.Size = New-Object System.Drawing.Size(430, 28)
$passwordBox.UseSystemPasswordChar = $true
$form.Controls.Add($passwordBox)

$saveButton = New-Object System.Windows.Forms.Button
$saveButton.Text = "บันทึกและปิด"
$saveButton.Location = New-Object System.Drawing.Point(270, 160)
$saveButton.Size = New-Object System.Drawing.Size(105, 32)
$saveButton.Add_Click({
    if ($passwordBox.Text.Length -eq 0) {
        [System.Windows.Forms.MessageBox]::Show("กรุณากรอกรหัสผ่านก่อนบันทึก", "ยังไม่ได้กรอกรหัสผ่าน", "OK", "Warning") | Out-Null
        return
    }
    $form.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $form.Close()
})
$form.Controls.Add($saveButton)

$cancelButton = New-Object System.Windows.Forms.Button
$cancelButton.Text = "ยกเลิก"
$cancelButton.Location = New-Object System.Drawing.Point(385, 160)
$cancelButton.Size = New-Object System.Drawing.Size(70, 32)
$cancelButton.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
$form.Controls.Add($cancelButton)
$form.AcceptButton = $saveButton
$form.CancelButton = $cancelButton

if ($form.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
    $passwordBox.Clear()
    $form.Dispose()
    return
}

try {
    $securePassword = ConvertTo-SecureString -String $passwordBox.Text -AsPlainText -Force
    $passwordBox.Clear()
    $protectedPassword = ConvertFrom-SecureString -SecureString $securePassword
    $values = [ordered]@{
        SQL_SERVER = $Server
        SQL_PORT = $Port
        SQL_DATABASE = $Database
        SQL_USER = $User
        SQL_PASSWORD_DPAPI = $protectedPassword
        SQL_CONNECTION_STRING = ""
    }

    $existing = [System.IO.File]::ReadAllLines($ConfigFile)
    $keys = @($values.Keys)
    $updated = @($existing | Where-Object {
        $line = $_
        -not ($keys | Where-Object { $line -match "^$([regex]::Escape($_))=" })
    })
    foreach ($key in $values.Keys) {
        $updated += "$key=$($values[$key])"
    }
    [System.IO.File]::WriteAllLines($ConfigFile, $updated, [System.Text.UTF8Encoding]::new($false))

    [System.Windows.Forms.MessageBox]::Show("การตั้งค่าถูกบันทึกแบบเข้ารหัสเฉพาะ Windows user นี้แล้ว ขั้นต่อไปคือทดสอบ Connection ใน ERP Sync Monitor", "บันทึกการตั้งค่าแล้ว", "OK", "Information") | Out-Null
}
finally {
    if ($securePassword) { $securePassword.Dispose() }
    $protectedPassword = $null
    $passwordBox.Clear()
    $form.Dispose()
}
