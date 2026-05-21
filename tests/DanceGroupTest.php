<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DanceGroupTest extends TestCase
{
    public function test_cisc_dance_group_normalizes_and_is_active(): void
    {
        $campaigns = zeffy_sync_fetch_campaigns('dummy', 'https://api.zeffy.com/api/v1/campaigns');
        $this->assertIsArray($campaigns);

        $found = null;
        foreach ($campaigns as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }
            if (isset($campaign['title']) && $campaign['title'] === 'GEN Dance Group') {
                $found = $campaign;
                break;
            }
        }

        $this->assertIsArray($found, 'GEN Dance Group not present in mock campaigns');

        $normalized = zeffy_sync_normalize_campaign($found, 'publish');
        $this->assertIsArray($normalized);
        $this->assertSame('29b6ee87-e638-457b-9bf4-351105cc6858', $normalized['campaign_id']);
        $this->assertSame('GEN Dance Group', $normalized['title']);
        $this->assertFalse($normalized['is_archived']);
        $this->assertSame('publish', $normalized['status']);

        $tags = zeffy_sync_calculate_lifecycle_tags(
            $normalized['start_date'] ?? null,
            $normalized['end_date'] ?? null,
            $normalized['api_status'] ?? '',
            $normalized['is_archived'] ?? false
        );
        $this->assertIsArray($tags);
        $this->assertContains('Active', $tags);
    }
}
