<?php
/** @var array{key:string,label:string,state:string}[] $steps */
?>
<ol class="wizard-steps">
    <?php foreach ($steps as $i => $step): ?>
        <li class="wizard-steps__step is-<?= htmlspecialchars($step['state'], ENT_QUOTES) ?>">
            <span class="wizard-steps__num"><?= $step['state'] === 'done' ? '✔' : (int) ($i + 1) ?></span>
            <span class="wizard-steps__label"><?= htmlspecialchars($step['label'], ENT_QUOTES) ?></span>
        </li>
    <?php endforeach; ?>
</ol>
