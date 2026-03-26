# Drupal Article Export to JSON

Drupal Article Export to JSON is a standalone PHP script that exports published Article nodes from Drupal 8 and 9 into a structured JSON file.

It connects directly to the Drupal database and resolves related content such as taxonomy terms, media, files, paragraphs, entity references, featured images, and videos. The resulting JSON is suitable for headless front ends, migrations, archiving, static site generation, or third‑party integrations.

This script does not require installing or enabling a Drupal module.

---

## Requirements

- PHP 8.0 or higher
- MySQL or MariaDB
- Drupal 8.x or 9.x
- Database credentials with read-only access

The script reads directly from the Drupal database and does not bootstrap Drupal.

---

## Installation

Clone the repository or copy the script into a directory accessible to PHP:

```
git clone https://github.com/your-org/drupal-article-export-to-json.git
cd drupal-article-export-to-json
```

Open `drupal-article-export-to-json.php` and configure the database connection:

```php
$host = '127.0.0.1';
$port = '3306';
$db   = 'drupal_db';
$user = 'drupal_user';
$pass = 'drupal_password';
```

---

## Steps (How to Run)

1. Confirm the database credentials are correct.
2. Open a terminal in the directory containing the script.
3. Run the exporter:

```
php drupal-article-export-to-json.php
```

4. Enter the start year and end year when prompted.
5. Wait for processing to complete.
6. Locate the generated `news_articles.json` file in the same directory.

---

## Output

The script produces a single JSON file named:

```
news_articles.json
```

This file contains an array of fully resolved Article objects.

---

## Example Output

```json
{
  "nid": 123,
  "title": "Sample Article",
  "published_date": "2023-05-14",
  "byline": "Jane Doe",
  "summary": "Short teaser text",
  "featured_article": true,
  "body_html": "<p>Article content...</p>",
  "inline_images": [
    "/sites/default/files/inline-image.jpg"
  ],
  "featured_image": {
    "file": {
      "filename": "hero.jpg",
      "url": "/sites/default/files/hero.jpg",
      "mime": "image/jpeg"
    }
  },
  "news_category": [],
  "news_tags": [],
  "department": [],
  "video": null,
  "paragraphs": []
}
```

---

## Notes

- Reads directly from the Drupal database.
- Drupal permissions and workflows are not enforced.
- Only published Articles with a published date are exported.
- Assumes content type `article` and featured images via Image Paragraphs.
- Field machine names may require adjustment.
- Use read-only DB credentials where possible.

---

## License

MIT License

---

## Contributing

Bug reports and pull requests are welcome.
