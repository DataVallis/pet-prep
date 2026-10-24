<?php

namespace App\Enums;

/**
 * Breed care needs (M5-R14 / R15 / R18 / R19 / R20, David 2026-10-24).
 *
 * Four CHILD tasks — mini-games like the brushing (M5-T14 scratch card, nails
 * = tap each claw), with the grooming model's fair share, ledger slots and
 * parent report and no further penalty (a missed slot is reported, no illness,
 * no extra statistic shown to the child):
 *  - `tooth_brushing` (M5-R15), `fold_cleaning` (M5-R14 face / skin folds),
 *    `ear_cleaning` (M5-R20), `nail_trim` (M5-R19).
 *
 * Two PARENT reminders — the parent confirms "done" in the parent app; never
 * a routine, never in the Care Score or the traffic light; shown in the report:
 *  - `groomer_visit` (M5-R18 professional groomer), `bath` (M5-R20).
 *
 * A breed has a task only where its source states a frequency (David
 * 2026-10-24): a `breed_stage_params` row of the task's key, value
 * [times, period_days], traced to docs/research/dog-data/data.json
 * (BreedStageParamsSeeder::DOG_CARE_TASKS). No row = no task.
 */
enum CareTask: string
{
    case ToothBrushing = 'tooth_brushing';
    case FoldCleaning = 'fold_cleaning';
    case EarCleaning = 'ear_cleaning';
    case NailTrim = 'nail_trim';
    case GroomerVisit = 'groomer_visit';
    case Bath = 'bath';

    public function isChildTask(): bool
    {
        return in_array($this, self::childTasks(), true);
    }

    /** @return list<self> */
    public static function childTasks(): array
    {
        return [self::ToothBrushing, self::FoldCleaning, self::EarCleaning, self::NailTrim];
    }

    /** @return list<self> */
    public static function parentTasks(): array
    {
        return [self::GroomerVisit, self::Bath];
    }

    /** @return list<string> */
    public static function childValues(): array
    {
        return array_map(fn (self $t) => $t->value, self::childTasks());
    }

    /** @return list<string> */
    public static function parentValues(): array
    {
        return array_map(fn (self $t) => $t->value, self::parentTasks());
    }

    /** 'child' | 'parent' — who does it. */
    public function actor(): string
    {
        return $this->isChildTask() ? 'child' : 'parent';
    }

    public function stageParamKey(): StageParamKey
    {
        return match ($this) {
            self::ToothBrushing => StageParamKey::ToothBrushingFrequency,
            self::FoldCleaning => StageParamKey::FoldCleaningFrequency,
            self::EarCleaning => StageParamKey::EarCleaningFrequency,
            self::NailTrim => StageParamKey::NailTrimFrequency,
            self::GroomerVisit => StageParamKey::GroomerVisitFrequency,
            self::Bath => StageParamKey::BathFrequency,
        };
    }

    /** The activities_log row of a completed task (child) / a parent confirmation. */
    public function activityType(): ActivityType
    {
        return match ($this) {
            self::ToothBrushing => ActivityType::BrushedTeeth,
            self::FoldCleaning => ActivityType::CleanedFolds,
            self::EarCleaning => ActivityType::CleanedEars,
            self::NailTrim => ActivityType::TrimmedNails,
            self::GroomerVisit => ActivityType::ParentGroomerVisit,
            self::Bath => ActivityType::ParentBathedPet,
        };
    }

    /** The ledger routine of a child task; null for a parent reminder (never scored). */
    public function routineType(): ?RoutineType
    {
        return match ($this) {
            self::ToothBrushing => RoutineType::ToothBrushing,
            self::FoldCleaning => RoutineType::FoldCleaning,
            self::EarCleaning => RoutineType::EarCleaning,
            self::NailTrim => RoutineType::NailTrim,
            default => null,
        };
    }

    /** The mini-game session kind of a child task; null for a parent reminder. */
    public function sessionKind(): ?CareSessionKind
    {
        return match ($this) {
            self::ToothBrushing => CareSessionKind::ToothBrushing,
            self::FoldCleaning => CareSessionKind::FoldCleaning,
            self::EarCleaning => CareSessionKind::EarCleaning,
            self::NailTrim => CareSessionKind::NailTrim,
            default => null,
        };
    }

    public static function forSessionKind(CareSessionKind $kind): ?self
    {
        foreach (self::childTasks() as $task) {
            if ($task->sessionKind() === $kind) {
                return $task;
            }
        }

        return null;
    }

    public static function forRoutineType(RoutineType $type): ?self
    {
        foreach (self::childTasks() as $task) {
            if ($task->routineType() === $type) {
                return $task;
            }
        }

        return null;
    }
}
