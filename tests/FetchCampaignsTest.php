<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FetchCampaignsTest extends TestCase
{
    public function test_fetch_campaigns_reads_mock(): void
    {
        $campaigns = zeffy_sync_fetch_campaigns('dummy', 'https://api.zeffy.com/api/v1/campaigns');
        $this->assertIsArray($campaigns);

        $mock = __DIR__ . '/../__mocks__/list-campaigns.json';
        $data = json_decode((string) file_get_contents($mock), true);
        $this->assertIsArray($data);
        $expected = isset($data['data']) && is_array($data['data']) ? count($data['data']) : 0;

        $this->assertCount($expected, $campaigns);
    }

    public function test_normalize_all_fetched_campaigns(): void
    {
        $campaigns = zeffy_sync_fetch_campaigns('dummy', 'https://api.zeffy.com/api/v1/campaigns');
        $this->assertIsArray($campaigns);

        foreach ($campaigns as $campaign) {
            $normalized = zeffy_sync_normalize_campaign($campaign, 'publish');
            $this->assertIsArray($normalized, 'Normalization failed for campaign: ' . json_encode($campaign));
            $this->assertArrayHasKey('campaign_id', $normalized);
            $this->assertArrayHasKey('title', $normalized);
            $this->assertArrayHasKey('status', $normalized);
        }
    }
}
