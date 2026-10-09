<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyMembership extends Model
{
    protected $fillable = ['company_id', 'user_id', 'role', 'status', 'permissions', 'invited_at', 'joined_at'];

    protected $casts = [
        'permissions' => 'array',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function can(string $permission): bool
    {
        if (in_array($this->role, ['owner', 'admin'], true)) {
            return true;
        }

        $defaults = [
            'manager' => ['view_team', 'view_inventory', 'manage_inventory', 'view_all_leads', 'create_leads', 'update_leads', 'archive_leads', 'reassign_leads', 'manage_tasks', 'view_deals', 'manage_deals', 'view_reports'],
            'agent' => ['view_team', 'view_inventory', 'view_own_leads', 'create_leads', 'update_own_leads', 'manage_own_tasks', 'view_own_deals', 'create_deals', 'update_own_deals', 'view_own_reports'],
            'operations' => ['view_team', 'view_inventory', 'manage_inventory', 'view_all_leads', 'create_leads', 'update_leads', 'archive_leads', 'manage_tasks', 'view_deals', 'manage_deals', 'export_data'],
            'finance' => ['view_team', 'view_deals', 'view_commissions', 'manage_commissions', 'view_reports'],
        ];

        return in_array($permission, array_unique(array_merge($defaults[$this->role] ?? [], $this->permissions ?? [])), true);
    }
}
