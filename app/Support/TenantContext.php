<?php

namespace App\Support;

use App\Models\Company;
use RuntimeException;

class TenantContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function company(): Company
    {
        if (! $this->company) {
            throw new RuntimeException('No company has been selected for this request.');
        }

        return $this->company;
    }

    public function id(): int
    {
        return (int) $this->company()->getKey();
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }
}
