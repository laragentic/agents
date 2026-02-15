# v0.2.0 Release Instructions

## Status

✅ **Tag Created**: v0.2.0 annotated tag created locally on commit `293b37c`  
✅ **Release Notes**: Documented in `RELEASE_NOTES_v0.2.0.md`  
⏳ **Tag Push**: Needs to be pushed to GitHub  
⏳ **GitHub Release**: Needs to be created

## What Was Done

1. Fetched all tags from the remote repository
2. Identified the latest release as v0.1.0
3. Analyzed changes since v0.1.0 (PR #1: Agent Skills System)
4. Created annotated tag v0.2.0 on commit 293b37c (main branch)
5. Prepared comprehensive release notes

## Tag Details

```bash
Tag: v0.2.0
Commit: 293b37c3d585b6a8632afef5916149412abeb2bc
Message: v0.2.0 - Agent Skills System
```

To verify the tag:
```bash
git show v0.2.0 --no-patch
```

## Next Steps (Requires Push Access)

### 1. Create and Push the Tag

The tag needs to be created on the main branch at commit 293b37c. You can create it using the release notes file:

```bash
# Ensure you're on the main branch and up to date
git checkout main
git pull origin main

# Create the annotated tag using the release notes
# Note: The first line of RELEASE_NOTES_v0.2.0.md serves as the title
git tag -a v0.2.0 293b37c -m "$(cat RELEASE_NOTES_v0.2.0.md)"

# Or create with a shorter message:
git tag -a v0.2.0 293b37c -m "v0.2.0 - Agent Skills System

This release introduces a comprehensive Agent Skills System following the agentskills.io specification.

See RELEASE_NOTES_v0.2.0.md for full details."

# Push the tag to GitHub
git push origin v0.2.0
```

### 2. Create GitHub Release

**Option A: Using GitHub CLI**
```bash
gh release create v0.2.0 \
  --title "v0.2.0 - Agent Skills System" \
  --notes-file RELEASE_NOTES_v0.2.0.md
```

**Option B: Using GitHub Web Interface**
1. Go to: https://github.com/laragentic/agents/releases/new
2. Select tag: `v0.2.0`
3. Release title: `v0.2.0 - Agent Skills System`
4. Copy content from `RELEASE_NOTES_v0.2.0.md` into the description
5. Click "Publish release"

## Automated Script

A helper script is provided: `./release-v0.2.0.sh`

This script will:
- Verify the tag exists locally
- Provide the exact commands needed to push the tag
- Show how to create the GitHub release

## Release Highlights

This minor version bump (0.1.0 → 0.2.0) includes:

- **Agent Skills System**: Complete implementation following agentskills.io spec
- **Progressive Disclosure**: Load only needed skills
- **Auto-Resolution**: Automatic skill matching based on relevance
- **53 New Tests**: Comprehensive test coverage
- **Full Documentation**: Tutorials, examples, and API docs

See `RELEASE_NOTES_v0.2.0.md` for complete details.
