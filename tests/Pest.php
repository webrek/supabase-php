<?php

declare(strict_types=1);

uses()
    ->beforeEach(function (): void {
        if (getenv('SUPABASE_URL') === false) {
            \PHPUnit\Framework\Assert::markTestSkipped('integration env not configured');
        }
    })
    ->in('Integration');
