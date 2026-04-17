<?php
/**
 * Note card partial — included by room.php
 * Expects: $note, $participantId, $isRevealed, $canVote, $canEditNotes, $myVoteIds, $room
 */
$isMine      = (int)$note['participant_id'] === $participantId;
$voteCount   = (int)($note['vote_count'] ?? 0);
$iVoted      = in_array((int)$note['id'], array_map('intval', $myVoteIds), true);
$isTopVoted  = $isRevealed && $voteCount >= 3;
?>
<div class="note-card <?= $isMine ? 'note-card--mine' : '' ?> <?= $isTopVoted ? 'note-card--top' : '' ?>"
     data-note-id="<?= (int)$note['id'] ?>"
     data-col-id="<?= (int)$note['column_id'] ?>">

  <div class="note-card__content" data-note-id="<?= (int)$note['id'] ?>">
    <p class="note-text"><?= e($note['content']) ?></p>
    <?php if ($canEditNotes && $isMine): ?>
    <div class="note-card__edit" style="display:none;">
      <textarea class="note-edit-input" maxlength="<?= NOTE_MAX_LENGTH ?>"><?= e($note['content']) ?></textarea>
      <div class="note-edit-actions">
        <button class="btn btn--sm btn--primary note-save-btn" data-note-id="<?= (int)$note['id'] ?>">Save</button>
        <button class="btn btn--sm btn--ghost note-cancel-btn">Cancel</button>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="note-card__footer">
    <?php if ($isRevealed && $canVote): ?>
    <button
      class="vote-btn <?= $iVoted ? 'vote-btn--active' : '' ?>"
      data-note-id="<?= (int)$note['id'] ?>"
      title="<?= $iVoted ? 'Remove vote' : 'Vote for this note' ?>"
    >
      👍 <span class="vote-count"><?= $voteCount ?></span>
    </button>
    <?php elseif ($isRevealed): ?>
    <span class="vote-display">👍 <?= $voteCount ?></span>
    <?php endif; ?>

    <?php if ($isMine && !$isRevealed): ?>
    <div class="note-card__mine-actions">
      <?php if ($canEditNotes): ?>
      <button class="btn-icon note-edit-btn" data-note-id="<?= (int)$note['id'] ?>" title="Edit">✏️</button>
      <?php endif; ?>
      <button class="btn-icon note-delete-btn" data-note-id="<?= (int)$note['id'] ?>" title="Delete">🗑</button>
    </div>
    <?php endif; ?>

    <?php if ($isMine && !$isRevealed): ?>
    <span class="note-card__mine-label">Your note</span>
    <?php endif; ?>
  </div>
</div>
