<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function test_normalize_unix_timestamp_with_seconds(): void
    {
        $this->assertSame(1600000000, zeffy_sync_normalize_unix_timestamp(1600000000));
    }

    public function test_normalize_unix_timestamp_with_milliseconds(): void
    {
        $this->assertSame(1600000000, zeffy_sync_normalize_unix_timestamp(1600000000000));
    }

    public function test_normalize_unix_timestamp_with_rfc_string(): void
    {
        $value = '2020-09-13T00:00:00Z';
        $expected = strtotime($value);
        $this->assertSame($expected, zeffy_sync_normalize_unix_timestamp($value));
    }

    public function test_normalize_unix_timestamp_invalid(): void
    {
        $this->assertNull(zeffy_sync_normalize_unix_timestamp('not-a-date'));
    }

    public function test_map_locale_for_url_cad_fr(): void
    {
        $this->assertSame('fr-CA', zeffy_sync_map_locale_for_url('fr', 'CAD'));
    }

    public function test_map_locale_for_url_cad_en(): void
    {
        $this->assertSame('en-CA', zeffy_sync_map_locale_for_url('en', 'cad'));
    }

    public function test_map_locale_for_url_fr(): void
    {
        $this->assertSame('fr', zeffy_sync_map_locale_for_url('FR', 'usd'));
    }

    public function test_map_locale_for_url_normalize(): void
    {
        $this->assertSame('pt-br', zeffy_sync_map_locale_for_url('pt_BR', 'usd'));
    }

    public function test_first_non_empty(): void
    {
        $this->assertSame('first', zeffy_sync_first_non_empty(null, '', [], 'first', 'second'));
    }

    public function test_normalize_campaign_url_preserves_slug_and_locale(): void
    {
        $campaign = [
            'url' => 'https://www.zeffy.com/en/ticketing/some-event',
            'locale' => 'en',
            'currency' => 'usd',
        ];

        $this->assertSame('https://www.zeffy.com/en/ticketing/some-event', zeffy_sync_normalize_campaign_url($campaign));
    }

    public function test_extract_campaign_content_first_candidate(): void
    {
        $campaign = ['description' => '', 'details' => 'Details here', 'summary' => 'Summary'];
        $this->assertSame('Details here', zeffy_sync_extract_campaign_content($campaign));
    }
}
