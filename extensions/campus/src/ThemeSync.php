<?php
namespace UCASSer\Campus;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;

/**
 * Single source of truth for the campus palette.
 *
 * Earlier versions moved colours with one-shot settings flags, so a database
 * created at a different time ended up with a different palette and no error
 * was ever raised. This class converges to the constants below on every run:
 * brand values are written when the stored value is empty or one of the colours
 * this project used before, and an administrator's custom colour is preserved
 * unless --force is passed.
 */
class ThemeSync
{
    public const PRIMARY = '#A71937';
    public const SECONDARY = '#C22A49';

    /** Colours written by earlier versions of this project. */
    private const LEGACY_PRIMARY = ['#536f90', '#4d698e', '#2563eb', '#bd1838', '#c8193b', '#2e5a7a'];
    private const LEGACY_TAG = ['#176d72', '#2563eb', '#2e5a7a'];
    private const LEGACY_ICON = 'fas fa-graduation-cap';

    public function __construct(private SettingsRepositoryInterface $settings, private Sections $sections)
    {
    }

    /** @return list<array{label:string,column:string,id:?int,current:string,expected:string,change:bool}> */
    public function plan(bool $force = false): array
    {
        $primary = strtolower(trim((string) ($this->settings->get('theme_primary_color') ?? '')));
        $secondary = strtolower(trim((string) ($this->settings->get('theme_secondary_color') ?? '')));
        $rows = [
            $this->row('theme_primary_color', 'theme_primary_color', null, $primary, self::PRIMARY, $force || $primary === '' || in_array($primary, self::LEGACY_PRIMARY, true)),
            $this->row('theme_secondary_color', 'theme_secondary_color', null, $secondary, self::SECONDARY, $force || $secondary === '' || in_array($secondary, self::LEGACY_PRIMARY, true)),
        ];
        foreach ($this->sections->rows() as $section) {
            $tag = Tag::query()->find($section->tag_id);
            if (!$tag) continue;
            $color = strtolower(trim((string) ($tag->color ?? '')));
            $rows[] = $this->row('tag:'.$tag->name, 'color', (int) $tag->id, $color, self::PRIMARY, $force || in_array($color, self::LEGACY_TAG, true));
            if (isset(Sections::ICONS[$section->key])) {
                $icon = (string) ($tag->icon ?? '');
                $expected = 'fas fa-'.Sections::ICONS[$section->key];
                $rows[] = $this->row('tag:'.$tag->name, 'icon', (int) $tag->id, $icon, $expected, $force || $icon === '' || $icon === self::LEGACY_ICON);
            }
        }
        return $rows;
    }

    /** @return list<array{label:string,column:string,id:?int,current:string,expected:string,change:bool}> */
    public function apply(bool $force = false): array
    {
        $rows = $this->plan($force);
        foreach ($rows as $row) {
            if (!$row['change']) continue;
            if ($row['id'] === null) {
                $this->settings->set($row['column'], $row['expected']);
            } else {
                Tag::query()->whereKey($row['id'])->update([$row['column'] => $row['expected']]);
            }
        }
        return $rows;
    }

    private function row(string $label, string $column, ?int $id, string $current, string $expected, bool $change): array
    {
        return ['label' => $label, 'column' => $column, 'id' => $id, 'current' => $current ?: '(empty)', 'expected' => $expected, 'change' => $change];
    }
}
