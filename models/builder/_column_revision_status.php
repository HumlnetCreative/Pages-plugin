<?php if (!$record->published_revision_id): ?>
    <span class="badge bg-warning text-dark">Nový koncept</span>
<?php elseif ($record->has_draft): ?>
    <span class="badge bg-info text-dark">Koncept</span>
<?php elseif (!$record->published_is_published): ?>
    <span class="badge bg-secondary">Publikováno, skryto</span>
<?php else: ?>
    <span class="badge bg-success">Publikováno</span>
<?php endif ?>
