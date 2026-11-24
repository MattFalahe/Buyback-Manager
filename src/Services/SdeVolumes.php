<?php

namespace BuybackManager\Services;

/**
 * Packaged volumes from the copy of CCP's static data that SeAT downloads
 * into storage/sde when it updates its SDE. SeAT's tables keep only the
 * assembled volume, but the packaged one is in the same files.
 *
 * The files are SeAT's: they are only ever read here, and an install
 * without them falls back to Janice and ESI.
 */
final class SdeVolumes
{
    /** Volumes this close are the same figure written twice. */
    private const SAME = 0.0001;

    public static function directory(?string $directory = null): string
    {
        return rtrim($directory ?? storage_path('sde'), '/\\');
    }

    /** The build SeAT last downloaded, or null when its files are not there. */
    public static function build(?string $directory = null): ?int
    {
        $file = self::directory($directory) . '/_sde.jsonl';
        if (! is_readable($file)) {
            return null;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ($record['_key'] ?? null) === 'sde' && isset($record['buildNumber'])) {
                return (int) $record['buildNumber'];
            }
        }

        return null;
    }

    /**
     * Every type in a group where at least one type packs smaller than it is
     * assembled, with what it takes up packaged. The other types of such a
     * group are kept too, at their own volume, so a type of that group
     * missing from the table is known to be newer than the file. A type CCP
     * gives no packaged volume does not repackage.
     *
     * @return array<int, array{group_id: int, packaged_volume: float}>
     */
    public static function read(?string $directory = null): array
    {
        $handle = @fopen(self::directory($directory) . '/types.jsonl', 'rb');
        if ($handle === false) {
            return [];
        }

        $groups = [];
        $volumes = [];
        $repackaging = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $type = json_decode($line, true);
                if (! is_array($type) || ! isset($type['_key'], $type['groupID'])) {
                    continue;
                }

                $typeId = (int) $type['_key'];
                $groupId = (int) $type['groupID'];
                $volume = isset($type['volume']) ? (float) $type['volume'] : null;
                $packaged = isset($type['packagedVolume']) ? (float) $type['packagedVolume'] : null;

                $groups[$typeId] = $groupId;
                $volumes[$typeId] = $packaged ?? $volume;
                if ($packaged !== null && $volume !== null && abs($volume - $packaged) > self::SAME) {
                    $repackaging[$groupId] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        $rows = [];
        foreach ($groups as $typeId => $groupId) {
            if (isset($repackaging[$groupId]) && ($volumes[$typeId] ?? 0) > 0) {
                $rows[$typeId] = ['group_id' => $groupId, 'packaged_volume' => $volumes[$typeId]];
            }
        }

        return $rows;
    }
}
