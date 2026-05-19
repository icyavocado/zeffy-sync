#!/usr/bin/env python3
import argparse
import base64
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

ZEFFY_CAMPAIGNS_URL = "https://www.zeffy.com/api/v1/campaigns"


class SyncError(Exception):
    pass


@dataclass
class Campaign:
    campaign_id: str
    title: str
    description: str
    status: str


class ZeffyClient:
    def __init__(self, api_key: str, opener: Optional[Any] = None):
        self.api_key = api_key
        self.opener = opener or urllib.request.urlopen

    def list_campaigns(self) -> List[Dict[str, Any]]:
        req = urllib.request.Request(
            ZEFFY_CAMPAIGNS_URL,
            headers={
                "Authorization": f"Bearer {self.api_key}",
                "Accept": "application/json",
            },
        )
        try:
            with self.opener(req) as response:
                data = json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            raise SyncError(f"Failed to fetch campaigns from Zeffy: HTTP {exc.code}") from exc
        except urllib.error.URLError as exc:
            raise SyncError(f"Failed to fetch campaigns from Zeffy: {exc.reason}") from exc

        if isinstance(data, list):
            return data
        if isinstance(data, dict):
            for key in ("data", "campaigns", "results"):
                value = data.get(key)
                if isinstance(value, list):
                    return value
        raise SyncError("Unexpected response shape from Zeffy campaigns API")


def _first_non_empty(*values: Any) -> str:
    for value in values:
        if value is None:
            continue
        text = str(value).strip()
        if text:
            return text
    return ""


def normalize_campaign(raw: Dict[str, Any]) -> Campaign:
    campaign_id = _first_non_empty(raw.get("id"), raw.get("campaign_id"))
    if not campaign_id:
        raise SyncError("Campaign is missing an id")

    title = _first_non_empty(raw.get("name"), raw.get("title"), f"Zeffy campaign {campaign_id}")
    description = _first_non_empty(
        raw.get("description"),
        raw.get("summary"),
        raw.get("details"),
    )
    status = _first_non_empty(raw.get("status"), "publish").lower()
    if status not in {"publish", "draft", "private", "pending"}:
        status = "publish"

    return Campaign(campaign_id=campaign_id, title=title, description=description, status=status)


def campaign_slug(campaign_id: str) -> str:
    normalized = re.sub(r"[^a-z0-9-]+", "-", campaign_id.lower())
    normalized = re.sub(r"-+", "-", normalized).strip("-")
    if not normalized:
        normalized = "campaign"
    return f"zeffy-event-{normalized}"


class WordPressClient:
    def __init__(self, base_url: str, username: str, app_password: str, opener: Optional[Any] = None):
        self.base_url = base_url.rstrip("/")
        self.username = username
        self.app_password = app_password
        self.opener = opener or urllib.request.urlopen

    def _headers(self) -> Dict[str, str]:
        token = base64.b64encode(f"{self.username}:{self.app_password}".encode("utf-8")).decode("ascii")
        return {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def _request(self, method: str, path: str, data: Optional[Dict[str, Any]] = None) -> Any:
        payload = None if data is None else json.dumps(data).encode("utf-8")
        req = urllib.request.Request(
            f"{self.base_url}{path}",
            data=payload,
            method=method,
            headers=self._headers(),
        )
        try:
            with self.opener(req) as response:
                body = response.read().decode("utf-8")
                return json.loads(body) if body else None
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8") if exc.fp is not None else ""
            raise SyncError(f"WordPress API error ({method} {path}): HTTP {exc.code} {body}") from exc
        except urllib.error.URLError as exc:
            raise SyncError(f"WordPress API connection error ({method} {path}): {exc.reason}") from exc

    def find_post_id_by_slug(self, slug: str) -> Optional[int]:
        query = urllib.parse.urlencode({"slug": slug, "per_page": 1})
        result = self._request("GET", f"/wp-json/wp/v2/posts?{query}")
        if isinstance(result, list) and result:
            item = result[0]
            if isinstance(item, dict) and isinstance(item.get("id"), int):
                return item["id"]
        return None

    def create_post(self, payload: Dict[str, Any]) -> int:
        result = self._request("POST", "/wp-json/wp/v2/posts", data=payload)
        if isinstance(result, dict) and isinstance(result.get("id"), int):
            return result["id"]
        raise SyncError("Unexpected WordPress create response")

    def update_post(self, post_id: int, payload: Dict[str, Any]) -> int:
        result = self._request("POST", f"/wp-json/wp/v2/posts/{post_id}", data=payload)
        if isinstance(result, dict) and isinstance(result.get("id"), int):
            return result["id"]
        raise SyncError("Unexpected WordPress update response")


def make_wp_payload(campaign: Campaign) -> Dict[str, Any]:
    slug = campaign_slug(campaign.campaign_id)
    return {
        "title": campaign.title,
        "content": campaign.description,
        "status": campaign.status,
        "slug": slug,
    }


def sync_campaigns_to_wordpress(zeffy: ZeffyClient, wordpress: WordPressClient, dry_run: bool = False) -> Dict[str, int]:
    campaigns = [normalize_campaign(item) for item in zeffy.list_campaigns()]

    created = 0
    updated = 0

    for campaign in campaigns:
        payload = make_wp_payload(campaign)
        post_id = wordpress.find_post_id_by_slug(payload["slug"])
        if dry_run:
            if post_id is None:
                created += 1
            else:
                updated += 1
            continue

        if post_id is None:
            wordpress.create_post(payload)
            created += 1
        else:
            wordpress.update_post(post_id, payload)
            updated += 1

    return {"total": len(campaigns), "created": created, "updated": updated}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Zeffy campaigns to WordPress posts")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be created/updated without writing")
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    zeffy_api_key = os.getenv("ZEFFY_API_KEY")
    wp_base_url = os.getenv("WP_BASE_URL")
    wp_username = os.getenv("WP_USERNAME")
    wp_app_password = os.getenv("WP_APP_PASSWORD")

    missing = [
        name
        for name, value in (
            ("ZEFFY_API_KEY", zeffy_api_key),
            ("WP_BASE_URL", wp_base_url),
            ("WP_USERNAME", wp_username),
            ("WP_APP_PASSWORD", wp_app_password),
        )
        if not value
    ]
    if missing:
        raise SyncError(f"Missing environment variables: {', '.join(missing)}")

    zeffy = ZeffyClient(api_key=zeffy_api_key)
    wordpress = WordPressClient(base_url=wp_base_url, username=wp_username, app_password=wp_app_password)

    result = sync_campaigns_to_wordpress(zeffy, wordpress, dry_run=args.dry_run)
    print(
        f"Synced {result['total']} campaign(s): "
        f"{result['created']} created, {result['updated']} updated"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SyncError as exc:
        print(f"Error: {exc}")
        raise SystemExit(1)
