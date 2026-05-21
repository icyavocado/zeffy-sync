<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NormalizeCampaignTest extends TestCase
{
    public function test_missing_id_returns_wp_error(): void
    {
        $result = zeffy_sync_normalize_campaign([], 'publish');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('zeffy_sync_missing_campaign_id', $result->get_error_code());
    }

    public function test_deleted_campaign_returns_wp_error(): void
    {
        $campaign = ['id' => 'abc123', 'deleted_at' => '2024-01-01'];
        $result = zeffy_sync_normalize_campaign($campaign, 'publish');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('zeffy_sync_deleted_campaign', $result->get_error_code());
    }

    public function test_unsupported_category_returns_wp_error(): void
    {
        $campaign = ['id' => 'abc123', 'category' => 'NotAType'];
        $result = zeffy_sync_normalize_campaign($campaign, 'publish');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('zeffy_sync_unsupported_campaign_category', $result->get_error_code());
    }

    public function test_active_status_maps_to_default(): void
    {
        $campaign = [
            'id' => 'c1',
            'title' => 'Event 1',
            'description' => 'Details',
            'status' => 'active',
            'category' => 'Event',
        ];

        $result = zeffy_sync_normalize_campaign($campaign, 'publish');
        $this->assertIsArray($result);
        $this->assertSame('c1', $result['campaign_id']);
        $this->assertSame('Event 1', $result['title']);
        $this->assertSame('Details', $result['content']);
        $this->assertSame('publish', $result['status']);
    }

    public function test_archived_forces_draft(): void
    {
        $campaign = [
            'id' => 'c2',
            'title' => 'Event 2',
            'description' => 'Details',
            'status' => 'active',
            'category' => 'Event',
            'is_archived' => true,
        ];

        $result = zeffy_sync_normalize_campaign($campaign, 'publish');
        $this->assertIsArray($result);
        $this->assertSame('draft', $result['status']);
    }

    public function test_occurrences_dates_aggregated(): void
    {
        $campaign = [
            'id' => 'c3',
            'title' => 'Event 3',
            'category' => 'Event',
            'occurrences' => [
                ['start_date' => 1700000000, 'end_date' => 1700003600],
                ['start_date' => 1690000000, 'end_date' => 1710000000],
            ],
        ];

        $result = zeffy_sync_normalize_campaign($campaign, 'publish');
        $this->assertIsArray($result);
        $this->assertSame(1690000000, $result['start_date']);
        $this->assertSame(1710000000, $result['end_date']);
    }
}
