<?php
/**
 * Drupal 8/9 Article Exporter
 * - Year range
 * - Featured image 
 * - Filters PDFs/non-images for featured image
 * - Video (Media)
 * - Paragraphs export (generic)
 * - Inline body images
 * - Byline, Summary, Featured boolean, Meta tags
 * - Department, News Category, Related News Tags
 * - JSON output
 */

/**
 * ======================
 * DB CONFIG
 * ======================
 */
$host = '127.0.0.1';
$port = '32797';
$db   = 'db';
$user = 'db';
$pass = 'db';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

/**
 * ======================
 * CONFIG
 * ======================
 */
$termFields = [
    'news_category' => [
        'table' => 'node__field_news_category',
        'target_column' => 'field_news_category_target_id',
    ],
    'news_tags' => [
        'table' => 'node__field_news_type',
        'target_column' => 'field_news_type_target_id',
    ],
];

/**
 * Media image field candidates.
 * Your Media type "Image" has an image field machine name: `image` (confirmed earlier).
 */
$mediaImageFieldCandidates = [
    'image',              // ✅ your site
    'thumbnail',          // fallback
    'field_media_image',  // fallback
    'field_image',        // fallback (other installs)
];
$GLOBALS['mediaImageFieldCandidates'] = $mediaImageFieldCandidates;

/**
 * Paragraph type "Image" has the machine-name field that stores the reference:
 * ✅ field_image (Entity reference)  <-- from your screenshot
 */
$featuredImageParagraphType = 'image';
$featuredImageParagraphFieldMachineName = 'field_image'; // paragraph field that points to media/file

/**
 * ======================
 * HELPERS
 * ======================
 */
function askYear(string $label): int {
    while (true) {
        echo "$label (YYYY): ";
        $input = trim(fgets(STDIN));
        if (preg_match('/^\d{4}$/', $input)) return (int)$input;
        echo "Invalid year.\n";
    }
}

function progressBar(int $done, int $total, int $size = 40): void {
    if ($total === 0) return;
    $percent = $done / $total;
    $bar = (int) floor($percent * $size);
    echo "\r[" . str_repeat("=", $bar) . str_repeat(" ", $size - $bar) . "] " . (int) floor($percent * 100) . "%";
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function fileUriToUrl(string $uri): string {
    if (str_starts_with($uri, 'public://')) {
        return '/sites/default/files/' . substr($uri, 9);
    }
    if (str_starts_with($uri, 'private://')) {
        // Private file delivery depends on Drupal configuration.
        return '/system/files/' . substr($uri, 10);
    }
    return $uri;
}

function loadFile(PDO $pdo, int $fid): ?array {
    $stmt = $pdo->prepare("
        SELECT fid, filename, uri, filemime
        FROM file_managed
        WHERE fid = ?
        LIMIT 1
    ");
    $stmt->execute([$fid]);
    $file = $stmt->fetch();
    if (!$file) return null;

    return [
        'fid' => (int)$file['fid'],
        'filename' => $file['filename'],
        'url' => fileUriToUrl($file['uri']),
        'mime' => $file['filemime'] ?? null,
    ];
}

function isImageFile(?array $file): bool {
    if (!$file) return false;

    if (!empty($file['mime']) && str_starts_with($file['mime'], 'image/')) {
        return true;
    }

    // Fallback to extension if mime is missing
    $ext = strtolower(pathinfo($file['filename'] ?? '', PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp','svg','avif'], true);
}

function extractImagesFromHtml(?string $html): array {
    if (!$html) return [];
    preg_match_all('/<img[^>]+src="([^">]+)"/i', $html, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

/**
 * ======================
 * TAXONOMY TERM RESOLVER
 * ======================
 */
function loadTerm(PDO $pdo, int $tid): ?array {
    $stmt = $pdo->prepare("
        SELECT tid, name, vid, langcode
        FROM taxonomy_term_field_data
        WHERE tid = ?
        ORDER BY langcode ASC
        LIMIT 1
    ");
    $stmt->execute([$tid]);
    $term = $stmt->fetch();
    if (!$term) return null;

    $alias = null;
    if (tableExists($pdo, 'path_alias')) {
        try {
            $aliasStmt = $pdo->prepare("
                SELECT alias
                FROM path_alias
                WHERE path = CONCAT('/taxonomy/term/', ?)
                ORDER BY id DESC
                LIMIT 1
            ");
            $aliasStmt->execute([$tid]);
            $alias = $aliasStmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $alias = null;
        }
    }

    return [
        'entity_type' => 'taxonomy_term',
        'id'  => (int)$term['tid'],
        'name' => $term['name'],
        'vid'  => $term['vid'],
        'url'  => $alias ?: ("/taxonomy/term/" . (int)$term['tid']),
    ];
}

function loadNodeTerms(PDO $pdo, int $nid, string $fieldTable, string $targetColumn): array {
    $stmt = $pdo->prepare("
        SELECT `$targetColumn` AS tid
        FROM `$fieldTable`
        WHERE entity_id = ?
        ORDER BY delta ASC
    ");
    $stmt->execute([$nid]);
    $tids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$tids) return [];

    $terms = [];
    foreach ($tids as $tid) {
        if (!is_numeric($tid)) continue;
        $term = loadTerm($pdo, (int)$tid);
        $terms[] = $term ?: ['entity_type' => 'unknown', 'id' => (int)$tid];
    }
    return $terms;
}

/**
 * ======================
 * MEDIA RESOLVER
 * ======================
 */
function loadMedia(PDO $pdo, int $mid): ?array {
    if (!tableExists($pdo, 'media_field_data')) return null;

    $stmt = $pdo->prepare("
        SELECT mid, name, bundle, langcode
        FROM media_field_data
        WHERE mid = ?
        ORDER BY langcode ASC
        LIMIT 1
    ");
    $stmt->execute([$mid]);
    $m = $stmt->fetch();
    if (!$m) return null;

    return [
        'entity_type' => 'media',
        'id' => (int)$m['mid'],
        'name' => $m['name'],
        'bundle' => $m['bundle'],
    ];
}

function resolveMediaToFile(PDO $pdo, int $mid, array $fieldCandidates): ?array {
    foreach ($fieldCandidates as $fieldName) {
        $table = "media__{$fieldName}";
        $col   = "{$fieldName}_target_id";

        if (!tableExists($pdo, $table)) continue;

        // Some sites have `deleted` on field tables; some don't. Try with it, then fallback.
        $sqlWithDeleted = "
            SELECT `$col` AS fid
            FROM `$table`
            WHERE entity_id = ?
              AND (deleted = 0 OR deleted IS NULL)
            ORDER BY delta ASC
            LIMIT 1
        ";

        try {
            $stmt = $pdo->prepare($sqlWithDeleted);
            $stmt->execute([$mid]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT `$col` AS fid
                FROM `$table`
                WHERE entity_id = ?
                ORDER BY delta ASC
                LIMIT 1
            ");
            $stmt->execute([$mid]);
        }

        $fid = $stmt->fetchColumn();
        if ($fid && is_numeric($fid)) {
            $file = loadFile($pdo, (int)$fid);
            if ($file) return $file;
        }
    }
    return null;
}

/**
 * Resolve an ID that might be media OR file.
 * Media-first avoids collisions and matches how most reference fields are configured.
 */
function resolveTargetToFile(PDO $pdo, int $targetId, array $mediaImageFieldCandidates): ?array {
    $media = loadMedia($pdo, $targetId);
    if ($media) {
        return resolveMediaToFile($pdo, $targetId, $mediaImageFieldCandidates);
    }
    return loadFile($pdo, $targetId);
}

/**
 * ======================
 * PARAGRAPH HELPERS
 * ======================
 */
function loadParagraphInfo(PDO $pdo, int $pid, ?int $revisionId = null): ?array {
    // Prefer paragraphs_item_field_data if available
    if (tableExists($pdo, 'paragraphs_item_field_data')) {
        if ($revisionId) {
            $stmt = $pdo->prepare("
                SELECT id, revision_id, type, langcode
                FROM paragraphs_item_field_data
                WHERE id = ? AND revision_id = ?
                LIMIT 1
            ");
            $stmt->execute([$pid, $revisionId]);
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'revision_id' => (int)$row['revision_id'],
                    'type' => $row['type'],
                    'langcode' => $row['langcode'] ?? null,
                ];
            }
        }

        $stmt = $pdo->prepare("
            SELECT id, revision_id, type, langcode
            FROM paragraphs_item_field_data
            WHERE id = ?
            ORDER BY revision_id DESC
            LIMIT 1
        ");
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id' => (int)$row['id'],
                'revision_id' => (int)$row['revision_id'],
                'type' => $row['type'],
                'langcode' => $row['langcode'] ?? null,
            ];
        }
    }

    // Fallback
    if (tableExists($pdo, 'paragraphs_item')) {
        $sql = $revisionId
            ? "SELECT id, revision_id, type FROM paragraphs_item WHERE id = ? AND revision_id = ? LIMIT 1"
            : "SELECT id, revision_id, type FROM paragraphs_item WHERE id = ? ORDER BY revision_id DESC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $revisionId ? $stmt->execute([$pid, $revisionId]) : $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id' => (int)$row['id'],
                'revision_id' => (int)$row['revision_id'],
                'type' => $row['type'],
                'langcode' => null,
            ];
        }
    }

    return null;
}

/**
 * Resolve Featured Image from:
 * node__field_featured_image (paragraph ref + paragraph revision)
 * -> paragraph type image
 * -> paragraph__field_image.field_image_target_id
 * -> media/file -> file_managed
 *
 * Filters out PDFs/non-images: returns first valid image, otherwise warning.
 */
function resolveFeaturedImageFromNode(
    PDO $pdo,
    int $nid,
    string $expectedParagraphType,
    string $paragraphImageFieldMachineName,
    array $mediaImageFieldCandidates
): ?array {
    if (!tableExists($pdo, 'node__field_featured_image')) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
          field_featured_image_target_id AS pid,
          field_featured_image_target_revision_id AS prid
        FROM node__field_featured_image
        WHERE entity_id = ?
          AND deleted = 0
        ORDER BY delta ASC
    ");
    $stmt->execute([$nid]);
    $refs = $stmt->fetchAll();
    if (!$refs) return null;

    $nonImageFirst = null;

    // Paragraph field table for the image reference inside the Image paragraph:
    $paraTable = "paragraph__{$paragraphImageFieldMachineName}";
    $paraTargetCol = "{$paragraphImageFieldMachineName}_target_id";

    foreach ($refs as $ref) {
        if (empty($ref['pid']) || !is_numeric($ref['pid'])) continue;

        $pid = (int)$ref['pid'];
        $prid = (!empty($ref['prid']) && is_numeric($ref['prid'])) ? (int)$ref['prid'] : null;

        $pinfo = loadParagraphInfo($pdo, $pid, $prid);

        // If paragraph info exists and is not the Image bundle, skip
        if ($pinfo && !empty($pinfo['type']) && $pinfo['type'] !== $expectedParagraphType) {
            continue;
        }

        // If the paragraph__field_image table doesn't exist, we can't resolve deterministically.
        if (!tableExists($pdo, $paraTable)) {
            // fallback: return paragraph info only
            return [
                'source' => 'paragraph',
                'paragraph_id' => $pid,
                'paragraph_revision_id' => $prid,
                'paragraph' => $pinfo,
                'warning' => "Missing table {$paraTable}.",
            ];
        }

        // paragraph field tables usually have entity_id and revision_id
        $cols = $pdo->query("DESCRIBE `$paraTable`")->fetchAll(PDO::FETCH_COLUMN);
        $hasRevisionId = in_array('revision_id', $cols, true);
        $hasDeleted = in_array('deleted', $cols, true);

        $where = "entity_id = ?";
        $params = [$pid];

        if ($hasRevisionId && $prid) {
            $where .= " AND revision_id = ?";
            $params[] = $prid;
        }
        if ($hasDeleted) {
            $where .= " AND deleted = 0";
        }

        $q = $pdo->prepare("
            SELECT `$paraTargetCol` AS target_id
            FROM `$paraTable`
            WHERE $where
            ORDER BY delta ASC
            LIMIT 1
        ");
        $q->execute($params);
        $targetId = $q->fetchColumn();

        if (!$targetId || !is_numeric($targetId)) {
            continue;
        }

        // Resolve to file (media-first)
        $file = resolveTargetToFile($pdo, (int)$targetId, $mediaImageFieldCandidates);
        if (!$file) continue;

        $result = [
            'source' => 'featured_image_paragraph',
            'node_id' => $nid,
            'paragraph_id' => $pid,
            'paragraph_revision_id' => $prid,
            'paragraph' => $pinfo,
            'paragraph_field_table' => $paraTable,
            'paragraph_target_column' => $paraTargetCol,
            'referenced_target_id' => (int)$targetId,
            'file' => $file,
        ];

        // Filter: only return real images
        if (isImageFile($file)) {
            return $result;
        }

        if (!$nonImageFirst) {
            $result['warning'] = 'Featured image resolves to a non-image file (PDF or other).';
            $nonImageFirst = $result;
        }
    }

    return $nonImageFirst;
}

/**
 * ======================
 * Generic entity reference resolver (Department/Scheduled updates)
 * ======================
 */
function resolveEntityReference(PDO $pdo, int $id): array {
    $t = loadTerm($pdo, $id);
    if ($t) return $t;

    if (tableExists($pdo, 'node_field_data')) {
        $stmt = $pdo->prepare("SELECT nid, title, type FROM node_field_data WHERE nid = ? LIMIT 1");
        $stmt->execute([$id]);
        $n = $stmt->fetch();
        if ($n) {
            return [
                'entity_type' => 'node',
                'id' => (int)$n['nid'],
                'title' => $n['title'],
                'bundle' => $n['type'],
                'url' => "/node/" . (int)$n['nid'],
            ];
        }
    }

    $m = loadMedia($pdo, $id);
    if ($m) return $m;

    if (tableExists($pdo, 'users_field_data')) {
        $stmt = $pdo->prepare("SELECT uid, name FROM users_field_data WHERE uid = ? LIMIT 1");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) {
            return [
                'entity_type' => 'user',
                'id' => (int)$u['uid'],
                'name' => $u['name'],
                'url' => "/user/" . (int)$u['uid'],
            ];
        }
    }

    if (tableExists($pdo, 'scheduled_update_field_data')) {
        $stmt = $pdo->prepare("SELECT id, type, name FROM scheduled_update_field_data WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $su = $stmt->fetch();
        if ($su) {
            return [
                'entity_type' => 'scheduled_update',
                'id' => (int)$su['id'],
                'type' => $su['type'],
                'name' => $su['name'] ?? null,
            ];
        }
    }

    return ['entity_type' => 'unknown', 'id' => $id];
}

function loadNodeEntityRefs(PDO $pdo, int $nid, string $table, string $targetColumn): array {
    if (!tableExists($pdo, $table)) return [];

    $stmt = $pdo->prepare("
        SELECT `$targetColumn` AS target_id
        FROM `$table`
        WHERE entity_id = ?
        ORDER BY delta ASC
    ");
    $stmt->execute([$nid]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return [];

    $out = [];
    foreach ($ids as $id) {
        if (!is_numeric($id)) continue;
        $out[] = resolveEntityReference($pdo, (int)$id);
    }
    return $out;
}

/**
 * ======================
 * Paragraph table meta (generic export)
 * ======================
 */
function buildParagraphFieldTableMeta(PDO $pdo): array {
    $tables = $pdo->query("SHOW TABLES LIKE 'paragraph__field_%'")->fetchAll(PDO::FETCH_COLUMN);
    $meta = [];

    foreach ($tables as $table) {
        $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('entity_id', $cols, true)) continue;

        $targetCols = [];
        foreach ($cols as $c) {
            if (str_ends_with($c, '_target_id')) $targetCols[] = $c;
        }

        $meta[$table] = [
            'cols' => $cols,
            'has_revision_id' => in_array('revision_id', $cols, true),
            'has_deleted' => in_array('deleted', $cols, true),
            'target_cols' => $targetCols,
        ];
    }

    return $meta;
}

/**
 * ======================
 * PROMPT
 * ======================
 */
echo "=== Article Exporter ===\n";
$startYear = askYear("Enter START year");
$endYear   = askYear("Enter END year");
if ($endYear < $startYear) exit("End year must be >= start year\n");

/**
 * ======================
 * FETCH ARTICLES
 * ======================
 */
$sql = "
SELECT
    n.nid,
    n.title,
    pub.field_published_date_value AS published_date,
    body.body_value,
    byline.field_byline_value AS byline,
    summary.field_summary_value AS summary,
    feat.field_featured_value AS featured_article,
    mt.field_meta_tags_value AS meta_tags_raw
FROM node_field_data n
LEFT JOIN node__field_published_date pub ON pub.entity_id = n.nid
LEFT JOIN node__body body ON body.entity_id = n.nid
LEFT JOIN node__field_byline byline ON byline.entity_id = n.nid
LEFT JOIN node__field_summary summary ON summary.entity_id = n.nid
LEFT JOIN node__field_featured feat ON feat.entity_id = n.nid
LEFT JOIN node__field_meta_tags mt ON mt.entity_id = n.nid
WHERE n.type = 'article'
  AND n.status = 1
  AND pub.field_published_date_value IS NOT NULL
  AND YEAR(pub.field_published_date_value) BETWEEN :start AND :end
ORDER BY pub.field_published_date_value ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':start' => $startYear, ':end' => $endYear]);
$nodes = $stmt->fetchAll();

/**
 * Build paragraph field metadata once for generic paragraph export.
 */
$paragraphFieldMeta = buildParagraphFieldTableMeta($pdo);

/**
 * ======================
 * PROCESS NODES
 * ======================
 */
$output = [];
$total = count($nodes);
$count = 0;

foreach ($nodes as $node) {
    $count++;
    progressBar($count, $total);

    $nid = (int)$node['nid'];

    $item = [
        'nid' => $nid,
        'title' => $node['title'],
        'published_date' => $node['published_date'],

        'byline' => $node['byline'] ?? null,
        'summary' => $node['summary'] ?? null,
        'featured_article' => isset($node['featured_article']) ? (bool)$node['featured_article'] : null,

        'meta_tags_raw' => $node['meta_tags_raw'] ?? null,

        'body_html' => $node['body_value'],
        'inline_images' => extractImagesFromHtml($node['body_value']),

        'news_category' => [],
        'news_tags' => [],

        'department' => [],
        'scheduled_updates' => [],

        'featured_image' => null,
        'video' => null,

        'paragraphs' => [],
    ];

    /**
     * Featured Image: Node -> Paragraph Image -> field_image -> Media/File -> File
     */
    $item['featured_image'] = resolveFeaturedImageFromNode(
        $pdo,
        $nid,
        $featuredImageParagraphType,
        $featuredImageParagraphFieldMachineName,
        $mediaImageFieldCandidates
    );

    /**
     * News Category + Tags
     */
    foreach ($termFields as $key => $cfg) {
        if (!isset($cfg['table'], $cfg['target_column'])) continue;
        if (!tableExists($pdo, $cfg['table'])) continue;
        $item[$key] = loadNodeTerms($pdo, $nid, $cfg['table'], $cfg['target_column']);
    }

    /**
     * Department
     */
    $item['department'] = loadNodeEntityRefs(
        $pdo,
        $nid,
        'node__field_department',
        'field_department_target_id'
    );

    /**
     * Scheduled updates
     */
    $item['scheduled_updates'] = loadNodeEntityRefs(
        $pdo,
        $nid,
        'node__scheduled_update',
        'scheduled_update_target_id'
    );

    /**
     * Video (Media)
     */
    if (tableExists($pdo, 'node__field_video')) {
        $vstmt = $pdo->prepare("
            SELECT field_video_target_id AS mid
            FROM node__field_video
            WHERE entity_id = ?
              AND deleted = 0
            ORDER BY delta ASC
            LIMIT 1
        ");
        $vstmt->execute([$nid]);
        $mid = $vstmt->fetchColumn();

        if ($mid && is_numeric($mid)) {
            $media = loadMedia($pdo, (int)$mid);
            if ($media) {
                $mediaFile = resolveMediaToFile($pdo, (int)$mid, $mediaImageFieldCandidates);
                $item['video'] = [
                    'source' => 'media',
                    'media' => $media,
                    'file' => $mediaFile,
                ];
            } else {
                $item['video'] = ['source' => 'unknown', 'id' => (int)$mid];
            }
        }
    }

    /**
     * Paragraph references (generic export)
     */
    if (tableExists($pdo, 'node__field_paragraph')) {
        $pstmt = $pdo->prepare("
            SELECT field_paragraph_target_id AS pid,
                   field_paragraph_target_revision_id AS prid
            FROM node__field_paragraph
            WHERE entity_id = ?
              AND deleted = 0
            ORDER BY delta ASC
        ");
        $pstmt->execute([$nid]);
        $refs = $pstmt->fetchAll();

        foreach ($refs as $ref) {
            if (empty($ref['pid']) || !is_numeric($ref['pid'])) continue;
            $pid = (int)$ref['pid'];
            $prid = (!empty($ref['prid']) && is_numeric($ref['prid'])) ? (int)$ref['prid'] : null;

            $pinfo = loadParagraphInfo($pdo, $pid, $prid);
            if (!$pinfo) $pinfo = ['id' => $pid, 'revision_id' => $prid, 'type' => null, 'langcode' => null];

            $paragraph = [
                'id' => $pid,
                'revision_id' => $prid,
                'type' => $pinfo['type'],
                'langcode' => $pinfo['langcode'],
                'field_rows' => [],
                'resolved_targets' => [],
            ];

            foreach ($paragraphFieldMeta as $table => $info) {
                $where = "entity_id = ?";
                $params = [$pid];

                if ($info['has_revision_id'] && $prid) {
                    $where .= " AND revision_id = ?";
                    $params[] = $prid;
                }
                if ($info['has_deleted']) {
                    $where .= " AND deleted = 0";
                }

                $sql2 = "SELECT * FROM `$table` WHERE $where ORDER BY delta ASC";
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute($params);
                $rows = $stmt2->fetchAll();
                if (!$rows) continue;

                $paragraph['field_rows'][$table] = $rows;

                foreach ($rows as $row) {
                    foreach ($info['target_cols'] as $targetCol) {
                        $val = $row[$targetCol] ?? null;
                        if (!$val || !is_numeric($val)) continue;

                        $file = resolveTargetToFile($pdo, (int)$val, $mediaImageFieldCandidates);
                        if ($file) {
                            $paragraph['resolved_targets'][] = [
                                'table' => $table,
                                'column' => $targetCol,
                                'target_id' => (int)$val,
                                'file' => $file,
                                'is_image' => isImageFile($file),
                            ];
                        }
                    }
                }
            }

            $item['paragraphs'][] = $paragraph;
        }
    }

    $output[] = $item;
}

echo "\nProcessing complete.\n";

/**
 * ======================
 * SAVE JSON
 * ======================
 */
file_put_contents(
    'news_articles.json',
    json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Exported to news_articles.json\n";