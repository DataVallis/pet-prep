<?php

namespace App\Policies;

use App\Models\ChallengeCertificate;
use App\Models\Family;
use App\Models\User;

/**
 * Certificates (M2-01d, family model ADR-012): any parent of the row's family
 * sees and generates; the child of the row may only see (open the PDF). Nobody
 * else — another family's row looks like a missing one (controllers → 404).
 */
class ChallengeCertificatePolicy
{
    public function view(User $user, ChallengeCertificate $certificate): bool
    {
        if ($user->isChild()) {
            return (int) $certificate->child_user_id === $user->id;
        }

        return $this->generate($user, $certificate);
    }

    public function generate(User $user, ChallengeCertificate $certificate): bool
    {
        return $user->isParent()
            && (Family::find($certificate->family_id)?->hasParent($user) ?? false);
    }
}
