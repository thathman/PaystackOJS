<?php

namespace APP\plugins\paymethod\paystack\classes;

use APP\facades\Repo;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

/**
 * Temporary compatibility for https://github.com/pkp/pkp-lib/issues/12885.
 *
 * Remove this helper when the minimum supported OJS release assigns APC
 * queued payments to the notified author instead of the requesting editor.
 */
class ApcOwnerCompatibility
{
    private const PUBLICATION_PAYMENT_TYPE = 7;

    public static function mayClaim(
        int $paymentType,
        int $ownerUserId,
        int $currentUserId,
        string $currentUserEmail,
        array $assignedAuthorIds,
        ?string $primaryAuthorEmail
    ): bool {
        if ($ownerUserId === $currentUserId) {
            return true;
        }
        if ($paymentType !== self::PUBLICATION_PAYMENT_TYPE || $currentUserEmail === '') {
            return false;
        }

        $assignedAuthorIds = array_values(array_unique(array_map('intval', $assignedAuthorIds)));
        if (!in_array($currentUserId, $assignedAuthorIds, true)) {
            return false;
        }

        if ($primaryAuthorEmail !== null && $primaryAuthorEmail !== '') {
            return strcasecmp($currentUserEmail, $primaryAuthorEmail) === 0;
        }

        return count($assignedAuthorIds) === 1;
    }

    public static function authorizeAndRepair($queuedPayment, $currentUser, $queuedPaymentDao): bool
    {
        if (!$queuedPayment || !$currentUser) {
            return false;
        }

        $currentUserId = (int) $currentUser->getId();
        $ownerUserId = (int) $queuedPayment->getUserId();
        if ($ownerUserId === $currentUserId) {
            return true;
        }

        if ((int) $queuedPayment->getType() !== self::PUBLICATION_PAYMENT_TYPE) {
            return false;
        }

        $submission = Repo::submission()->get((int) $queuedPayment->getAssocId());
        if (!$submission || (int) $submission->getData('contextId') !== (int) $queuedPayment->getContextId()) {
            return false;
        }

        $assignedAuthorIds = StageAssignment::withSubmissionIds([$submission->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->get()
            ->pluck('user_id')
            ->all();

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return false;
        }

        $primaryAuthor = $publication->getPrimaryAuthor();
        $primaryAuthorEmail = $primaryAuthor ? (string) $primaryAuthor->getEmail() : null;

        if (!self::mayClaim(
            (int) $queuedPayment->getType(),
            $ownerUserId,
            $currentUserId,
            (string) $currentUser->getEmail(),
            $assignedAuthorIds,
            $primaryAuthorEmail
        )) {
            return false;
        }

        $queuedPayment->setUserId($currentUserId);
        $queuedPaymentDao->updateObject($queuedPayment->getId(), $queuedPayment);
        Logger::info((int) $queuedPayment->getContextId(), 'Temporarily corrected APC queued-payment owner for pkp/pkp-lib#12885', [
            'queuedPaymentId' => (int) $queuedPayment->getId(),
            'oldUserId' => $ownerUserId,
            'newUserId' => $currentUserId,
        ]);

        return true;
    }
}
