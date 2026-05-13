<?php

declare(strict_types=1);

namespace PhpLlamaBench\CaseStudy;

final class Record
{
    public function __construct(
        public int    $sourceId = 0,
        public string $firstName = '',
        public string $lastName = '',
        public string $email = '',
        public string $phone = '',
        public string $countryName = '',
        public string $countryIso = '',
        public string $city = '',
        public string $address = '',
        public string $postalCode = '',
        public string $company = '',
        public string $jobTitle = '',
        public string $signupDate = '',
    ) {}

    public function reset(): void
    {
        $this->sourceId    = 0;
        $this->firstName   = '';
        $this->lastName    = '';
        $this->email       = '';
        $this->phone       = '';
        $this->countryName = '';
        $this->countryIso  = '';
        $this->city        = '';
        $this->address     = '';
        $this->postalCode  = '';
        $this->company     = '';
        $this->jobTitle    = '';
        $this->signupDate  = '';
    }
}
