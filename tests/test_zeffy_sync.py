import unittest

from zeffy_sync import Campaign, SyncError, campaign_slug, make_wp_payload, normalize_campaign, sync_campaigns_to_wordpress


class FakeZeffy:
    def __init__(self, payload):
        self.payload = payload

    def list_campaigns(self):
        return self.payload


class FakeWordPress:
    def __init__(self, existing_slugs=None):
        self.existing_slugs = existing_slugs or {}
        self.created_payloads = []
        self.updated_payloads = []

    def find_post_id_by_slug(self, slug):
        return self.existing_slugs.get(slug)

    def create_post(self, payload):
        self.created_payloads.append(payload)
        return 101

    def update_post(self, post_id, payload):
        self.updated_payloads.append((post_id, payload))
        return post_id


class ZeffySyncTests(unittest.TestCase):
    def test_normalize_campaign_uses_common_fields(self):
        raw = {
            "id": "CMP_123",
            "name": "Spring Gala",
            "description": "Fundraising event",
            "status": "publish",
        }

        campaign = normalize_campaign(raw)

        self.assertEqual(campaign.campaign_id, "CMP_123")
        self.assertEqual(campaign.title, "Spring Gala")
        self.assertEqual(campaign.description, "Fundraising event")
        self.assertEqual(campaign.status, "publish")

    def test_normalize_campaign_raises_when_id_missing(self):
        with self.assertRaises(SyncError):
            normalize_campaign({"name": "No id"})

    def test_campaign_slug_sanitizes_identifier(self):
        self.assertEqual(campaign_slug("CMP 123/ABC"), "zeffy-event-cmp-123-abc")

    def test_make_wp_payload_maps_campaign_to_post(self):
        campaign = Campaign(campaign_id="abc123", title="Title", description="Body", status="publish")

        payload = make_wp_payload(campaign)

        self.assertEqual(payload["title"], "Title")
        self.assertEqual(payload["content"], "Body")
        self.assertEqual(payload["status"], "publish")
        self.assertEqual(payload["slug"], "zeffy-event-abc123")

    def test_sync_creates_and_updates_posts(self):
        zeffy = FakeZeffy(
            [
                {"id": "new", "name": "New Event", "description": "New", "status": "publish"},
                {"id": "existing", "name": "Existing Event", "description": "Old", "status": "publish"},
            ]
        )
        wordpress = FakeWordPress(existing_slugs={"zeffy-event-existing": 42})

        summary = sync_campaigns_to_wordpress(zeffy, wordpress)

        self.assertEqual(summary, {"total": 2, "created": 1, "updated": 1})
        self.assertEqual(len(wordpress.created_payloads), 1)
        self.assertEqual(len(wordpress.updated_payloads), 1)
        self.assertEqual(wordpress.updated_payloads[0][0], 42)

    def test_sync_dry_run_does_not_write(self):
        zeffy = FakeZeffy(
            [
                {"id": "new", "name": "New Event", "description": "New", "status": "publish"},
                {"id": "existing", "name": "Existing Event", "description": "Old", "status": "publish"},
            ]
        )
        wordpress = FakeWordPress(existing_slugs={"zeffy-event-existing": 42})

        summary = sync_campaigns_to_wordpress(zeffy, wordpress, dry_run=True)

        self.assertEqual(summary, {"total": 2, "created": 1, "updated": 1})
        self.assertEqual(wordpress.created_payloads, [])
        self.assertEqual(wordpress.updated_payloads, [])


if __name__ == "__main__":
    unittest.main()
