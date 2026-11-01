<?php
declare(strict_types=1);

function initialize_homepage(PDO $db): void
{
    if(setting($db,'homepage_reviews_initialized',false))return;
    // Translated excerpts of real individual Google reviews featured by SteamRex.
    // These are selected excerpts, never a live aggregate rating.
    $reviews=[
        ['reference-catharina','Catharina','So happy with the service and the result.',5],
        ['reference-marianne','Marianne','The car looks lovely, inside and out.',5],
        ['reference-niclas','Niclas','Great result. I’m very satisfied.',4],
    ];
    foreach($reviews as $index=>$review)execute($db,'INSERT IGNORE INTO reviews (id,author,quote,rating,source_label,source_url,sort_order) VALUES (?,?,?,?,?,?,?)',[...$review,'Google · featured by SteamRex','https://steamrex.com/',$index]);
    set_setting($db,'homepage_reviews_initialized',true);
}

function homepage_reviews(PDO $db): string
{
    $html='';
    foreach(rows($db,'SELECT * FROM reviews WHERE active = 1 AND deleted_at IS NULL ORDER BY sort_order,id LIMIT 12') as $review){
        $stars=str_repeat('★',(int)$review['rating']).str_repeat('☆',5-(int)$review['rating']);
        $html.='<article class="review-card"><div class="review-top"><span class="review-stars" aria-label="'.(int)$review['rating'].' out of 5 stars">'.$stars.'</span><span class="review-quote-mark" aria-hidden="true">“</span></div><blockquote><p>“'.html_escape($review['quote']).'”</p></blockquote><div class="review-author"><span class="review-avatar" aria-hidden="true">'.html_escape(mb_substr($review['author'],0,1)).'</span><div><strong>'.html_escape($review['author']).'</strong><a href="'.html_escape($review['source_url']).'" target="_blank" rel="noopener noreferrer">'.html_escape($review['source_label']).' ↗</a></div></div></article>';
    }
    return $html?:'<p>Read customer experiences on Google.</p>';
}
