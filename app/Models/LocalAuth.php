<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Whoever is signed in on this device (one row). Screens ask can('...')
 * with the server's permission names (App\Auth\Permissions on the
 * backend), never the role name.
 */
class LocalAuth extends Model
{
    protected $table = 'local_auth';

    protected $fillable = [
        'remote_user_id', 'name', 'email', 'role', 'permissions', 'role_display_name', 'status',
        'barangay_id', 'barangay_name', 'api_token', 'logged_in_at', 'identity_checked_at',
    ];

    protected $hidden = ['api_token'];

    protected $casts = [
        'logged_in_at' => 'datetime',
        'identity_checked_at' => 'datetime',
        'permissions' => 'array',
    ];

    /**
     * The server's permissions per role, for the ones this app uses. Only
     * a fallback for a row saved before permissions were stored (an
     * upgraded device); the next /auth/me replaces it with the real list.
     */
    private const ROLE_FALLBACK = [
        'barangay_official' => [
            'operations.access', 'page.monitoring', 'puroks.view', 'families.re_enroll',
            'count_submissions.submit', 'box_relief.distribute', 'centers.edit', 'centers.set_status',
            'evacuees.manage', 'evacuees.transfer', 'transfers.request', 'transfers.receive',
        ],
        'cswd_personnel' => [
            'operations.access', 'scope.all_barangays', 'page.monitoring', 'puroks.view',
            'count_submissions.review', 'count_submissions.submit_on_behalf', 'centers.review', 'centers.set_status',
            'evacuees.transfer', 'evacuees.request_correction', 'transfers.manage', 'page.dashboard',
        ],
        'administrator' => ['barangays.view', 'users.manage'],
    ];

    public static function current(): ?self
    {
        return static::query()->latest('logged_in_at')->first();
    }

    /** @return list<string> */
    public function permissionList(): array
    {
        return is_array($this->permissions) ? $this->permissions : (self::ROLE_FALLBACK[$this->role] ?? []);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissionList(), true);
    }

    /** May work with evacuee data at all (CSWD and barangay officials; never the administrator). */
    public function hasOperationsAccess(): bool
    {
        return $this->can('operations.access');
    }

    /**
     * The barangay official's desktop: registration at their own centers.
     * Decided by permission: a barangay-scoped operations user.
     */
    public function isBarangayOfficial(): bool
    {
        return $this->hasOperationsAccess() && ! $this->can('scope.all_barangays');
    }

    /** CSWD on the desktop: read-only Monitoring and Final counts. */
    public function isCityWide(): bool
    {
        return $this->hasOperationsAccess() && $this->can('scope.all_barangays');
    }

    public function roleLabel(): string
    {
        return $this->role_display_name ?: \Illuminate\Support\Str::headline((string) $this->role);
    }

    /** Copies a user block from /auth/login or /auth/me onto this row. */
    public static function attributesFromUser(array $user): array
    {
        return [
            'remote_user_id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'permissions' => array_key_exists('permissions', $user) ? array_values((array) $user['permissions']) : null,
            'role_display_name' => $user['role_display_name'] ?? null,
            'status' => $user['status'] ?? null,
            'barangay_id' => $user['barangay']['id'] ?? null,
            'barangay_name' => $user['barangay']['name'] ?? null,
        ];
    }
}
