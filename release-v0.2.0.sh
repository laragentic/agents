#!/bin/bash
# Release v0.2.0 Completion Script
# 
# NOTE: This script must be run by someone with push access to the repository.
# The v0.2.0 tag has been created locally and needs to be pushed to GitHub.

set -e

echo "📋 Release v0.2.0 Status:"
echo "  ✅ Tag created locally on commit 293b37c"
echo "  ✅ Release notes prepared in RELEASE_NOTES_v0.2.0.md"
echo "  ⏳ Tag needs to be pushed to GitHub"
echo "  ⏳ GitHub release needs to be created"
echo ""

# Check if tag exists locally
if git show-ref --verify --quiet refs/tags/v0.2.0; then
    echo "🏷️  Local tag v0.2.0 found:"
    git log -1 --format="%H %s" v0.2.0
    echo ""
else
    echo "❌ Tag v0.2.0 not found locally. Please create it first:"
    echo "   git tag -a v0.2.0 293b37c -F RELEASE_NOTES_v0.2.0.md"
    exit 1
fi

echo "🚀 To complete the release, run these commands:"
echo ""
echo "1. Push the tag to GitHub:"
echo "   git push origin v0.2.0"
echo ""
echo "2. Create the GitHub release using gh CLI:"
echo "   gh release create v0.2.0 \\"
echo "     --title 'v0.2.0 - Agent Skills System' \\"
echo "     --notes-file RELEASE_NOTES_v0.2.0.md"
echo ""
echo "   Or create it via the web interface:"
echo "   https://github.com/laragentic/agents/releases/new?tag=v0.2.0"
echo ""
