<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;

/** Validates and normalizes the deliberately small section reveal contract. */
final class SectionMotion
{
    public const EFFECTS = ['none', 'fade', 'fade-up', 'fade-left', 'fade-right', 'scale-in'];
    public const DURATIONS = ['fast' => 300, 'normal' => 500, 'slow' => 700];
    public const COUNT_UP_DURATIONS = ['fast' => 1200, 'normal' => 2000, 'slow' => 4000];
    public const DELAYS = [0, 100, 200, 300, 400];
    public const STAGGER_STEP_MS = 75;
    public const STAGGER_MAX_ITEMS = 7;

    public static function defaults(): array
    {
        return [
            'effect' => 'none',
            'duration' => 'normal',
            'duration_ms' => self::DURATIONS['normal'],
            'delay_ms' => 0,
            'stagger_items' => false,
            'count_up' => [
                'enabled' => false,
                'duration' => 'normal',
                'duration_ms' => self::COUNT_UP_DURATIONS['normal'],
            ],
            'has_reveal' => false,
            'has_count_up' => false,
            'is_active' => false,
        ];
    }

    /** @throws \ValidationException */
    public static function normalize(string $type, mixed $value): array
    {
        if ($value === null || $value === []) {
            return self::defaults();
        }
        if (!is_array($value)) {
            throw new \ValidationException(['style.motion' => 'Nastavení pohybu musí být mapování.']);
        }

        $unknown = array_diff(array_keys($value), ['effect', 'duration', 'delay_ms', 'stagger_items', 'count_up']);
        if ($unknown) {
            throw new \ValidationException(['style.motion' => 'Nastavení pohybu obsahuje neznámé volby.']);
        }

        $effect = is_string($value['effect'] ?? null) ? $value['effect'] : 'none';
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new \ValidationException(['style.motion.effect' => 'Neplatný efekt pohybu.']);
        }
        $registry = SectionRegistry::instance();
        if ($effect !== 'none' && !$registry->supportsMotion($type)) {
            throw new \ValidationException(['style.motion.effect' => 'Tento typ sekce pohybové efekty nepodporuje.']);
        }

        $duration = is_string($value['duration'] ?? null) ? $value['duration'] : 'normal';
        if ($effect !== 'none' && !array_key_exists($duration, self::DURATIONS)) {
            throw new \ValidationException(['style.motion.duration' => 'Neplatná rychlost pohybu.']);
        }

        $rawDelay = $value['delay_ms'] ?? 0;
        $delay = is_int($rawDelay) || (is_string($rawDelay) && ctype_digit($rawDelay)) ? (int) $rawDelay : -1;
        if ($effect !== 'none' && !in_array($delay, self::DELAYS, true)) {
            throw new \ValidationException(['style.motion.delay_ms' => 'Neplatné zpoždění pohybu.']);
        }

        $rawStagger = $value['stagger_items'] ?? false;
        if (!in_array($rawStagger, [false, true, 0, 1, '0', '1'], true)) {
            throw new \ValidationException(['style.motion.stagger_items' => 'Neplatné nastavení postupného odhalení položek.']);
        }
        $stagger = in_array($rawStagger, [true, 1, '1'], true);
        if ($effect !== 'none' && $stagger && !$registry->supportsMotionStagger($type)) {
            throw new \ValidationException(['style.motion.stagger_items' => 'Tento typ sekce nepodporuje postupné odhalení položek.']);
        }

        if ($effect === 'none') {
            $duration = 'normal';
            $delay = 0;
            $stagger = false;
        }

        $countUp = self::normalizeCountUp($type, $value['count_up'] ?? [], $registry);

        return [
            'effect' => $effect,
            'duration' => $duration,
            'duration_ms' => self::DURATIONS[$duration],
            'delay_ms' => $delay,
            'stagger_items' => $stagger,
            'count_up' => $countUp,
            'has_reveal' => $effect !== 'none',
            'has_count_up' => $countUp['enabled'],
            'is_active' => $effect !== 'none' || $countUp['enabled'],
        ];
    }

    private static function normalizeCountUp(string $type, mixed $value, SectionRegistry $registry): array
    {
        $defaults = self::defaults()['count_up'];
        if ($value === null || $value === []) {
            return $defaults;
        }
        if (!is_array($value)) {
            throw new \ValidationException(['style.motion.count_up' => 'Nastavení počítadla musí být mapování.']);
        }
        if (array_diff(array_keys($value), ['enabled', 'duration'])) {
            throw new \ValidationException(['style.motion.count_up' => 'Nastavení počítadla obsahuje neznámé volby.']);
        }

        $rawEnabled = $value['enabled'] ?? false;
        if (!in_array($rawEnabled, [false, true, 0, 1, '0', '1'], true)) {
            throw new \ValidationException(['style.motion.count_up.enabled' => 'Neplatné zapnutí počítadla.']);
        }
        $enabled = in_array($rawEnabled, [true, 1, '1'], true);
        if (!$enabled) {
            return $defaults;
        }
        if (!$registry->supportsMotionCountUp($type)) {
            throw new \ValidationException(['style.motion.count_up.enabled' => 'Tento typ sekce nepodporuje počítadla.']);
        }

        $duration = is_string($value['duration'] ?? null) ? $value['duration'] : 'normal';
        if (!array_key_exists($duration, self::COUNT_UP_DURATIONS)) {
            throw new \ValidationException(['style.motion.count_up.duration' => 'Neplatná rychlost počítadla.']);
        }

        return [
            'enabled' => true,
            'duration' => $duration,
            'duration_ms' => self::COUNT_UP_DURATIONS[$duration],
        ];
    }

    /** Normalizes persisted data and removes the no-op default from section JSON. */
    public static function normalizeSection(Section $section): void
    {
        $style = (array) $section->style;
        $motion = self::normalize((string) $section->type, $style['motion'] ?? []);
        $persisted = [];
        if ($motion['has_reveal']) {
            $persisted = [
                'effect' => $motion['effect'],
                'duration' => $motion['duration'],
                'delay_ms' => $motion['delay_ms'],
                'stagger_items' => $motion['stagger_items'],
            ];
        }
        if ($motion['has_count_up']) {
            $persisted['count_up'] = [
                'enabled' => true,
                'duration' => $motion['count_up']['duration'],
            ];
        }
        if ($persisted) {
            $style['motion'] = $persisted;
        }
        else {
            unset($style['motion']);
        }
        $section->style = $style;
    }

    /** Rendering must fail open if legacy or externally supplied JSON is invalid. */
    public static function presentation(Section $section): array
    {
        try {
            return self::normalize((string) $section->type, data_get($section->style, 'motion', []));
        }
        catch (\ValidationException) {
            return self::defaults();
        }
    }
}
