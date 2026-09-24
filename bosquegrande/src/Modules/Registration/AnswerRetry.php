<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Core\Schema;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * Saves the registration and profile questions to the encrypted record.
 * If the write fails, the answer tables are repaired once and the write is
 * retried, so a partial database restore cannot silently drop answers.
 */
final class AnswerRetry
{
    public function __construct(
        private ConstituentStore $store,
        private Schema $schema,
    ) {
    }

    /**
     * Returns true only when the answers were written. With $verified false
     * (the form was not submitted from this person's signed-in session),
     * existing answers are kept and only blank ones are filled.
     *
     * @param array<int, string> $demographics
     * @param array<int, string> $relationship
     * @param array<int, string> $heard
     */
    public function saveRequired(
        int $localId,
        array $demographics,
        string $demographicsOther,
        string $ageRange,
        array $relationship,
        array $heard = [],
        string $heardOther = '',
        string $promote = '',
        bool $verified = true
    ): bool {
        if ($localId < 1 || !$this->store->available()) return false;
        $write = fn(): bool => PrefillResolver::storePersonalAnswers(
            $this->store,
            $localId,
            array_values($demographics),
            $demographicsOther,
            $ageRange,
            array_values($relationship),
            array_values($heard),
            $heardOther,
            $promote,
            !$verified
        );
        if ($write()) return true;
        $this->schema->repairAnswerStorage();
        return $write();
    }
}
