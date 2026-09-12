<?php

return [

    // Сколько дней удалённый чат лежит в корзине, прежде чем исчезнет
    // окончательно. 0 — удалять сразу, без корзины.
    'trash_lifetime_days' => (int) env('CHAT_TRASH_LIFETIME_DAYS', 30),

];
