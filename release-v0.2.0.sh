#!/bin/bash
# Release v0.2.0 Completion Script
# 
# NOTE: This script must be run by someone with push access to the repository.

set -e

COMMIT_SHA="293b37c3d585b6a8632afef5916149412abeb2bc"
TAG_NAME="v0.2.0"
TAG_TITLE="v0.2.0 - Agent Skills System"

echo "📋 Release v0.2.0 Status:"
echo "  Target commit: $COMMIT_SHA"
echo "  Tag name: $TAG_NAME"
echo ""

# Check if tag exists locally
if git show-ref --verify --quiet refs/tags/$TAG_NAME; then
    echo "✅ Local tag $TAG_NAME found:"
    git log -1 --format="%H %s" $TAG_NAME
    TAG_EXISTS=true
else
    echo "ℹ️  Tag $TAG_NAME not found locally (this is expected)"
    TAG_EXISTS=false
fi

echo ""
echo "🚀 To complete the release:"
echo ""

if [ "$TAG_EXISTS" = false ]; then
    echo "1. Create the tag:"
    echo "   git checkout main"
    echo "   git pull origin main"
    echo "   git tag -a $TAG_NAME $COMMIT_SHA -m \"$TAG_TITLE"
    echo ""
    echo "This release introduces a comprehensive Agent Skills System."
    echo "See RELEASE_NOTES_v0.2.0.md for full details.\""
    echo ""
fi

echo "2. Push the tag to GitHub:"
echo "   git push origin $TAG_NAME"
echo ""
echo "3. Create the GitHub release:"
echo "   gh release create $TAG_NAME \\"
echo "     --title '$TAG_TITLE' \\"
echo "     --notes-file RELEASE_NOTES_v0.2.0.md"
echo ""
echo "   Or via web: https://github.com/laragentic/agents/releases/new?tag=$TAG_NAME"
echo ""
