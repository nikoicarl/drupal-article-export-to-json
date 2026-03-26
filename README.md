# Drupal Article Export to JSON

A standalone PHP script for exporting **Drupal 8 / 9 Article nodes** into a rich, structured JSON file.  
Designed for developers who need a deep, entity-aware export without installing a Drupal module.

This exporter connects directly to a Drupal database and resolves Articles along with their related taxonomy, media, files, paragraphs, and entity references into a clean JSON output suitable for headless builds, migrations, archiving, or integrations.

---

## Features

- ✅ Year-based Article filtering (interactive CLI prompt)
- ✅ Exports **published Articles only**
- ✅ Full node content:
  - Title, published date
  - Body HTML
  - Inline body images extraction
  - Byline, summary, featured flag
  - Raw meta tags data
- ✅ Featured Image support via **Paragraph → Media → File**
  - Filters out PDFs and non-image files
  - Emits warnings when non-image files are encountered
- ✅ Media & file resolution
  - Media-first, file fallback
  - Supports multiple image field machine names
  - Converts `public://` and `private://` URIs into URLs
- ✅ Taxonomy export
  - News Categories
  - News Tags
  - Includes term metadata and path aliases
- ✅ Entity references
  - Department
  - Scheduled updates
- ✅ Video (Media) export
- ✅ Generic Paragraphs export
  - Discovers paragraph field tables automatically
  - Preserves paragraph IDs, revisions, bundle types, and language
  - Resolves media/file references inside paragraph fields
- ✅ Pretty-printed, human-readable JSON output

---

## Requirements

- PHP **8.0+**
- MySQL / MariaDB
- Drupal **8.x or 9.x**
- Database credentials with read access

> ⚠️ This script reads directly from the database and does **not** bootstrap Drupal.

---

## Installation

1. Clone this repository or copy the script into a working directory:

   ```bash
   git clone https://github.com/your-org/drupal-article-export-to-json.git
   cd drupal-article-export-to-json
   ``
