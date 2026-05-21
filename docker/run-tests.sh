#!/usr/bin/env bash
set -euo pipefail

IMAGE_TAG="zeffy-sync-tests:latest"

echo "Building test Docker image..."
docker build -t "$IMAGE_TAG" -f docker/test-runner/Dockerfile .

echo "Running tests inside container..."
docker run --rm "$IMAGE_TAG"
