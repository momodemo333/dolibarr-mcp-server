<?php

declare(strict_types=1);

namespace DolibarrMcp\Tests\Support;

use DolibarrMcp\Support\ListFilterTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Every case here starts from the incident that motivated the class: a lookup
 * by e-mail returned an unrelated company because the filter never reached the
 * database. The contract under test is that a filter is either applied, or
 * refused out loud — never dropped.
 */
class ListFilterTranslatorTest extends TestCase
{
    private ListFilterTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ListFilterTranslator();
    }

    // -- The reported incident -----------------------------------------------

    public function testThirdpartyEmailBecomesASqlfilterCriterion(): void
    {
        $out = $this->translator->translate('thirdparties', ['email' => 'someone@example.com']);

        $this->assertNull($out['error']);
        $this->assertSame(["(t.email:=:'someone@example.com')"], $out['sqlfilters']);
        $this->assertSame([], $out['params'], 'email is not a native parameter and must not be sent as one');
    }

    public function testContactEmailBecomesASqlfilterCriterion(): void
    {
        $out = $this->translator->translate('contacts', ['email' => 'someone@example.com']);

        $this->assertNull($out['error']);
        $this->assertSame(["(t.email:=:'someone@example.com')"], $out['sqlfilters']);
    }

    public function testThirdpartyNameIsRewrittenToTheRealColumn(): void
    {
        $out = $this->translator->translate('thirdparties', ['name' => 'Acme']);

        $this->assertSame(["(t.nom:=:'Acme')"], $out['sqlfilters']);
    }

    public function testInvoiceSocidAndNumericStatusAreBothHonoured(): void
    {
        // The exact call seen in the customer's activity log.
        $out = $this->translator->translate('invoices', ['socid' => '30', 'status' => '1']);

        $this->assertNull($out['error']);
        $this->assertSame(['thirdparty_ids' => '30', 'status' => 'unpaid'], $out['params']);
        $this->assertSame([], $out['sqlfilters']);
    }

    // -- Native parameters keep working --------------------------------------

    public function testNativeParametersArePassedThrough(): void
    {
        $out = $this->translator->translate('thirdparties', ['mode' => 1, 'category' => 4]);

        $this->assertSame(['mode' => '1', 'category' => '4'], $out['params']);
        $this->assertSame([], $out['sqlfilters']);
    }

    public function testThirdpartyIdsIsKeptOnContacts(): void
    {
        $out = $this->translator->translate('contacts', ['thirdparty_ids' => '1,2,3']);

        $this->assertSame(['thirdparty_ids' => '1,2,3'], $out['params']);
    }

    public function testTicketsUseTheirOwnSocidParameterRatherThanThirdpartyIds(): void
    {
        $out = $this->translator->translate('tickets', ['socid' => 30]);

        $this->assertSame(['socid' => '30'], $out['params'], 'tickets is the one endpoint that really declares socid');
    }

    public function testTextualInvoiceStatusStillUsesTheNativeParameter(): void
    {
        $out = $this->translator->translate('invoices', ['status' => 'paid']);

        $this->assertSame(['status' => 'paid'], $out['params']);
    }

    public function testListValuesAreJoinedForIdParameters(): void
    {
        $out = $this->translator->translate('invoices', ['thirdparty_ids' => [1, 2, 3]]);

        $this->assertSame(['thirdparty_ids' => '1,2,3'], $out['params']);
    }

    // -- Composition ----------------------------------------------------------

    public function testNativeAndColumnFiltersCompose(): void
    {
        $out = $this->translator->translate('thirdparties', ['mode' => 1, 'email' => 'a@b.c', 'town' => 'Paris']);

        $this->assertSame(['mode' => '1'], $out['params']);
        $this->assertSame(["(t.email:=:'a@b.c')", "(t.town:=:'Paris')"], $out['sqlfilters']);
    }

    // -- Refusals rather than silence -----------------------------------------

    public function testAmbiguousKeyIsRefusedWithGuidance(): void
    {
        $out = $this->translator->translate('contacts', ['name' => 'Dupont']);

        $this->assertNotNull($out['error']);
        $this->assertSame('AMBIGUOUS_FILTER', $out['error']['code']);
        $this->assertStringContainsString('lastname', $out['error']['message']);
    }

    public function testUnknownStatusWordIsRefusedWithTheAcceptedList(): void
    {
        $out = $this->translator->translate('invoices', ['status' => 'overdue']);

        $this->assertNotNull($out['error']);
        $this->assertSame('INVALID_STATUS_FILTER', $out['error']['code']);
        $this->assertStringContainsString('unpaid', $out['error']['message']);
    }

    public function testReservedArgumentUsedAsFilterIsRefused(): void
    {
        $out = $this->translator->translate('thirdparties', ['limit' => 10]);

        $this->assertSame('RESERVED_FILTER_KEY', $out['error']['code']);
    }

    public function testKeyThatIsNotAFieldNameIsRefused(): void
    {
        $out = $this->translator->translate('thirdparties', ['t.email; DROP TABLE' => 'x']);

        $this->assertSame('INVALID_FILTER_KEY', $out['error']['code']);
    }

    public function testNestedValueIsRefused(): void
    {
        $out = $this->translator->translate('thirdparties', ['email' => ['nested' => ['deep']]]);

        $this->assertSame('INVALID_FILTER_VALUE', $out['error']['code']);
    }

    /**
     * Dolibarr parses criteria with a regex that stops at a parenthesis, so a
     * value carrying one would corrupt the whole expression. Refusing is the
     * only honest option; the message names the way out.
     */
    public function testValueWithParenthesisIsRefusedAndPointsToSqlfilters(): void
    {
        $out = $this->translator->translate('thirdparties', ['name' => 'Acme (Ltd)']);

        $this->assertSame('UNSUPPORTED_FILTER_VALUE', $out['error']['code']);
        $this->assertStringContainsString('sqlfilters', $out['error']['message']);
        $this->assertStringContainsString('t.nom', $out['error']['message']);
    }

    // -- Escaping -------------------------------------------------------------

    /**
     * An apostrophe must survive: Dolibarr's dolForgeSQLCriteriaCallback()
     * captures the quoted value greedily and runs $db->escape() on it, so
     * O'Brien reaches SQL correctly. What we must not do is mangle it here.
     */
    public function testApostropheIsCarriedThroughUnchanged(): void
    {
        $out = $this->translator->translate('thirdparties', ['name' => "O'Brien"]);

        $this->assertNull($out['error']);
        $this->assertSame(["(t.nom:=:'O'Brien')"], $out['sqlfilters']);
    }

    public function testAccentsAndSpacesAreCarriedThroughUnchanged(): void
    {
        $out = $this->translator->translate('thirdparties', ['town' => 'Évelette sur Meuse']);

        $this->assertSame(["(t.town:=:'Évelette sur Meuse')"], $out['sqlfilters']);
    }

    // -- Unknown resources ----------------------------------------------------

    /**
     * A custom-module endpoint has no contract here. Falling back to a column
     * equality is right: if the column exists the filter works, and if it does
     * not the query fails loudly instead of returning everything.
     */
    public function testUnknownResourceFallsBackToColumnEquality(): void
    {
        $out = $this->translator->translate('somecustommodule', ['ref' => 'X-1']);

        $this->assertNull($out['error']);
        $this->assertSame(["(t.ref:=:'X-1')"], $out['sqlfilters']);
    }

    /**
     * setup/extrafields is the one setup path that declares elementtype, and
     * LLM.md documents that call. Turning it into a column filter would have
     * broken a working example.
     */
    public function testSetupExtrafieldsKeepsItsNativeElementtype(): void
    {
        $out = $this->translator->translate('setup/extrafields', ['elementtype' => 'user']);

        $this->assertSame(['elementtype' => 'user'], $out['params']);
        $this->assertSame([], $out['sqlfilters']);
    }

    public function testOtherSetupDictionariesKeepTheirSharedSelectors(): void
    {
        $out = $this->translator->translate('setup/countries', ['active' => 1, 'lang' => 'en_US']);

        $this->assertSame(['active' => '1', 'lang' => 'en_US'], $out['params']);
    }

    public function testSetupDictionaryColumnStillFallsBackToACriterion(): void
    {
        $out = $this->translator->translate('setup/countries', ['code' => 'BE']);

        $this->assertSame(["(t.code:=:'BE')"], $out['sqlfilters']);
    }

    public function testEmptyFiltersProduceNothing(): void
    {
        $out = $this->translator->translate('thirdparties', []);

        $this->assertSame([], $out['params']);
        $this->assertSame([], $out['sqlfilters']);
        $this->assertNull($out['error']);
    }
}
