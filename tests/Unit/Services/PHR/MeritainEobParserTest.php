<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\MeritainEobParser;
use PHPUnit\Framework\TestCase;

class MeritainEobParserTest extends TestCase
{
    public function test_legacy_layout_preserves_claim_and_financial_columns(): void
    {
        $text = <<<'EOB'
EMPLOYEE BENEFIT PLAN                         Print Date:05-03-22
 PROCEDURE                                                                                                                               OTHER     PYMT          PATIENT
 / REVENUE       DATES OF SERVICE          TOTAL         PROVIDER INELIGIBLE NOTES APPLIED                      APPLIED       BEN.      CARRIER'S MADE BY        RESPON-
    CODE                                  CHARGES        DISCOUNT AMOUNT           TO DED.                     TO COPAY        %          PYMT     PLAN          SIBILITY
70486            04-25-22                     361.00         173.13                     a                                        100                    187.87        0.00
                          TOTALS              361.00         173.13                                                                                     187.87        0.00
                                                                                                                          Claim No:      F07CI49
Processed Under Medical                                                                                                  Participant:    BENJAMIN WILLIAM HERILA
Plan For Services Provided By                              Check #                          Amount                            ID No:     7988000353-1
NANCY JANE FISCHBEIN                                       74371185                         $187.87                        Address:      99 RAUSCH ST UNIT 526
                                                                                                                    Processed On:        05-03-22       By: SY3
Patient:      BENJAMIN WILLIAM HERILA
Group Name:        META PLATFORMS INC
Group No:       16404.002
EOB;

        $parser = new MeritainEobParser;
        $parsed = $parser->parse($text, 'EOB_F07CI49.pdf');

        self::assertSame('F07CI49', $parsed['claim_number']);
        self::assertSame('medical', $parsed['claim_type']);
        self::assertSame('2022-04-25', $parsed['lines'][0]['service_start']);
        self::assertSame('70486', $parsed['lines'][0]['procedure_code']);
        self::assertSame('173.13', $parsed['lines'][0]['provider_discount']);
        self::assertSame(['a'], $parsed['lines'][0]['notes_applied']);
        self::assertSame('187.87', $parsed['total_plan_payment']);
    }

    public function test_modern_layout_preserves_description_revenue_code_and_payment_fields(): void
    {
        $text = <<<'EOB'
Meritain Health                                      Prepared On: 12/10/2024
Group Name: META PLATFORMS INC
Group #: 16404
Insured: BENJAMIN WILLIAM HERILA
Insured #: 7988000353-1
Claim #: IN93W43                                          Provider: UCSF MEDICAL CENTER
Patient: BENJAMIN WILLIAM HERILA                           Patient #: H9328213700
 Treatment Service/                                      Billed    Provider      Ineligible Applied to Applied to     Other   Payment       Paid Claim     Patient
   Dates   Rev Code               Description           Amount     Discount       Amount Deductible      CoPay      Payment    Amount       At Notes Responsible
  11/27/24   80048 / 0301         Laboratory            $396.00     $185.72         $0.00      $0.00       $0.00      $0.00    $210.28 100%             a           $0.00
                             Column Totals              $396.00      $185.72        $0.00      $0.00       $0.00      $0.00     $210.28                       $0.00
 Accumulators                                                                        Payment Details
Description                      Satisfied                  Claim Year     Paid To                                                  Check #            Amount
Individual In Network Deductible $0 of $200.00              2024           UCSF MEDICAL CENTER                                      111685383           $210.28
EOB;

        $parser = new MeritainEobParser;
        $parsed = $parser->parse($text, 'EOB_IN93W43.pdf');
        $line = $parsed['lines'][0];

        self::assertSame('IN93W43', $parsed['claim_number']);
        self::assertSame('2024-11-27', $line['service_start']);
        self::assertSame('0301', $line['revenue_code']);
        self::assertSame('Laboratory', $line['description']);
        self::assertSame('210.28', $line['plan_payment']);
        self::assertSame('100', $line['benefit_percent']);
        self::assertSame('111685383', $parsed['check_number']);
        self::assertSame('UCSF MEDICAL CENTER', $parsed['payment_to']);
    }
}
