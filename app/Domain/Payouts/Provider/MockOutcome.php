<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

/**
 * What the mock provider will do on a given call.
 *
 * The brief asked for three behaviours. There are four here, because splitting the
 * timeout in two is what makes the design interesting:
 *
 *   TimeoutAfterSuccess — the money MOVED and we never heard. A later status check
 *                         says "succeeded". Resending would pay twice.
 *   TimeoutBeforeAnything — nothing happened and we never heard. A later status
 *                         check says "not found". Releasing is safe.
 *
 * From our side these two are INDISTINGUISHABLE at the moment of the timeout. That
 * is the entire problem: the correct response cannot depend on which one it was,
 * because we cannot know. Both must lead to "unknown", and only asking resolves it.
 */
enum MockOutcome: string
{
    case Succeed = 'succeed';
    case FailPermanently = 'fail_permanently';
    case TimeoutAfterSuccess = 'timeout_after_success';
    case TimeoutBeforeAnything = 'timeout_before_anything';
}
