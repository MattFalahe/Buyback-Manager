<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackProgrammeAccess;
use Illuminate\Support\Facades\DB;

/**
 * Who is named on which buyback programme. An account counts when it is
 * named itself or holds a SeAT role that is named. SeAT's own tables are
 * only read here.
 */
final class ProgrammeAccess
{
    /** The permission each kind of access needs besides being named. */
    public const NEEDS = [
        BuybackProgrammeAccess::MANAGER => 'buyback-manager.settings',
        BuybackProgrammeAccess::VIEWER => 'buyback-manager.view',
    ];

    public const ADMIN_PERMISSION = 'buyback-manager.admin';

    /** @var array<int, int[]> */
    private array $roles = [];

    /**
     * Corporations whose programme names this account, or one of its
     * roles, for this access.
     *
     * @return int[]
     */
    public function corporationIds(int $userId, string $access): array
    {
        $roleIds = $this->roleIds($userId);

        return DB::table('buyback_manager_programme_access')
            ->join('buyback_manager_settings', 'buyback_manager_settings.id', '=', 'buyback_manager_programme_access.setting_id')
            ->where('buyback_manager_programme_access.access', $access)
            ->where(function ($named) use ($userId, $roleIds) {
                $named->where(function ($user) use ($userId) {
                    $user->where('buyback_manager_programme_access.subject_type', BuybackProgrammeAccess::USER)
                        ->where('buyback_manager_programme_access.subject_id', $userId);
                });
                if ($roleIds !== []) {
                    $named->orWhere(function ($role) use ($roleIds) {
                        $role->where('buyback_manager_programme_access.subject_type', BuybackProgrammeAccess::ROLE)
                            ->whereIn('buyback_manager_programme_access.subject_id', $roleIds);
                    });
                }
            })
            ->distinct()
            ->pluck('buyback_manager_settings.corporation_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Name a SeAT account or role on a programme. Naming it twice changes
     * nothing.
     *
     * @return string|null  what was wrong, or null once it is named
     */
    public function name(int $settingId, string $access, string $subjectType, int $subjectId): ?string
    {
        if (! in_array($access, BuybackProgrammeAccess::ACCESS, true)) {
            return 'Pick manager or viewer.';
        }
        if (! in_array($subjectType, BuybackProgrammeAccess::SUBJECTS, true)) {
            return 'Pick a SeAT account or a SeAT role.';
        }

        $isRole = $subjectType === BuybackProgrammeAccess::ROLE;
        if ($subjectId < 1 || ! DB::table($isRole ? 'roles' : 'users')->where('id', $subjectId)->exists()) {
            return $isRole ? 'That SeAT role does not exist.' : 'That SeAT account does not exist.';
        }

        BuybackProgrammeAccess::firstOrCreate([
            'setting_id' => $settingId,
            'access' => $access,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);

        return null;
    }

    public function unname(int $settingId, int $accessId): void
    {
        BuybackProgrammeAccess::where('setting_id', $settingId)->where('id', $accessId)->delete();
    }

    /** @return int[] */
    public function roleIds(int $userId): array
    {
        return $this->roles[$userId] ??= DB::table('role_user')
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether a SeAT account or role carries a permission, as SeAT grants a
     * plain one: a SeAT admin account holds every permission, otherwise one
     * of its roles has to. Buyback's own admin permission covers the rest.
     * Only used to warn about a name that will not work; access itself is
     * always SeAT's own check on the signed-in user.
     */
    public function holds(string $subjectType, int $subjectId, string $permission): bool
    {
        if ($subjectType === BuybackProgrammeAccess::USER) {
            if ((bool) DB::table('users')->where('id', $subjectId)->value('admin')) {
                return true;
            }
            $roleIds = $this->roleIds($subjectId);
        } else {
            $roleIds = [$subjectId];
        }

        if ($roleIds === []) {
            return false;
        }

        return DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permission_role.role_id', $roleIds)
            ->whereIn('permissions.title', [$permission, self::ADMIN_PERMISSION])
            ->exists();
    }

    /**
     * Everyone named on these programmes, by programme, each with a name to
     * show and what stops it working: gone from SeAT, or missing the
     * permission its access needs.
     *
     * @param  int[]  $settingIds
     * @return array<int, list<array{id: int, access: string, subject_type: string, subject_id: int, name: string, missing: bool, lacks: ?string}>>
     */
    public function entries(array $settingIds): array
    {
        $rows = BuybackProgrammeAccess::whereIn('setting_id', $settingIds)
            ->orderBy('access')
            ->orderBy('subject_type')
            ->orderBy('id')
            ->get();

        $accounts = $this->accountNames($rows->where('subject_type', BuybackProgrammeAccess::USER)->pluck('subject_id')->all());
        $roles = $this->roleNames($rows->where('subject_type', BuybackProgrammeAccess::ROLE)->pluck('subject_id')->all());

        $entries = [];
        foreach ($rows as $row) {
            $names = $row->subject_type === BuybackProgrammeAccess::ROLE ? $roles : $accounts;
            $missing = ! isset($names[$row->subject_id]);
            $needs = self::NEEDS[$row->access] ?? null;

            $entries[$row->setting_id][] = [
                'id' => (int) $row->id,
                'access' => $row->access,
                'subject_type' => $row->subject_type,
                'subject_id' => (int) $row->subject_id,
                'name' => $names[$row->subject_id] ?? ($row->subject_type === BuybackProgrammeAccess::ROLE ? 'Role #' : 'Account #') . $row->subject_id,
                'missing' => $missing,
                'lacks' => ! $missing && $needs !== null && ! $this->holds($row->subject_type, (int) $row->subject_id, $needs) ? $needs : null,
            ];
        }

        return $entries;
    }

    /**
     * SeAT accounts, each by its main character's name.
     *
     * @param  int[]  $userIds
     * @return array<int, string>
     */
    public function accountNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')
            ->leftJoin('character_infos', 'character_infos.character_id', '=', 'users.main_character_id')
            ->whereIn('users.id', array_values(array_unique($userIds)))
            ->get(['users.id', 'users.name', 'character_infos.name as main_name'])
            ->mapWithKeys(fn ($user) => [(int) $user->id => (string) ($user->main_name ?: $user->name)])
            ->all();
    }

    /**
     * @param  int[]|null  $roleIds  every role when null
     * @return array<int, string>
     */
    public function roleNames(?array $roleIds = null): array
    {
        if ($roleIds === []) {
            return [];
        }

        return DB::table('roles')
            ->when($roleIds !== null, fn ($query) => $query->whereIn('id', array_values(array_unique($roleIds))))
            ->orderBy('title')
            ->pluck('title', 'id')
            ->mapWithKeys(fn ($title, $id) => [(int) $id => (string) $title])
            ->all();
    }

    /**
     * SeAT accounts with a character whose name matches, so an account can
     * be found by any of its characters, each shown by its main.
     *
     * @return list<array{id: int, name: string, matched: string}>
     */
    public function searchAccounts(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = '%' . addcslashes($query, '\\%_') . '%';

        $matched = DB::table('refresh_tokens')
            ->join('character_infos', 'character_infos.character_id', '=', 'refresh_tokens.character_id')
            ->whereNull('refresh_tokens.deleted_at')
            ->where('character_infos.name', 'like', $like)
            ->orderBy('character_infos.name')
            ->limit(200)
            ->get(['refresh_tokens.user_id', 'character_infos.name']);

        $characters = [];
        foreach ($matched as $row) {
            $characters[(int) $row->user_id][] = (string) $row->name;
        }

        $names = $this->accountNames(array_keys($characters));
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $accounts = [];
        foreach (array_slice($names, 0, $limit, true) as $userId => $name) {
            $others = array_values(array_diff($characters[$userId], [$name]));
            $accounts[] = ['id' => $userId, 'name' => $name, 'matched' => implode(', ', array_slice($others, 0, 3))];
        }

        return $accounts;
    }
}
