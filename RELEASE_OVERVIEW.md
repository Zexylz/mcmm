# Release Workflow Overview

This document provides an overview of the release automation and version management workflows in this repository.

## 1. Automated Release Logic (`release.yml`)

This is the main workflow that handles the actual creation of releases and artifacts.

| Trigger Event | Function / Job | Description |
| :--- | :--- | :--- |
| **Push `v*` Tag** | **Create GitHub Release (tag)** | Creates a formal GitHub Release. **Uses AI to generate release notes from code diffs.** Generates Unraid plugin files. |
| **Release Created** | **Unraid Plugin Build** | Listens for the `release` event. Builds the `.txz` package and `.plg` file, calculates checksums, and attaches them to the release. |

## 2. Manual Version Management (`bump-version.yml`)

This workflow allows you to trigger a release manually by bumping the version number.

- **Trigger:** Manual (`workflow_dispatch`).
- **Inputs:** `bump_type` (major, minor, patch).
- **Action:**
    1.  Calculates next version (e.g., `1.0.0` -> `1.0.1`).
    2.  Updates `package.json`.
    3.  Creates and pushes a new `v*` tag.
    4.  **Chain Reaction:** The new tag triggers the `release.yml` workflow automatically.

## 3. Maintenance (`reset-releases.yml`)

This is a maintenance workflow to reset the repository state.

- **Trigger:** Manual (`workflow_dispatch`).
- **Inputs:** `confirm` (must be "yes").
- **Action:**
    1.  **Deletes ALL** GitHub Releases and git tags (`v*`, `nightly-*`).
    2.  Resets `package.json` to `0.0.1`.
    3.  Creates a fresh `v0.0.1` tag and release.
