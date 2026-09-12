<?php

return [
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'email'    => 'Поле «:attribute» должно быть корректным адресом почты.',
    'unique'   => 'Такое значение поля «:attribute» уже используется.',
    'min'      => [
        'string'  => 'Поле «:attribute» должно быть не короче :min символов.',
        'numeric' => 'Поле «:attribute» не может быть меньше :min.',
    ],
    'max' => [
        'string'  => 'Поле «:attribute» должно быть не длиннее :max символов.',
        'numeric' => 'Поле «:attribute» не может быть больше :max.',
    ],
    'confirmed' => 'Пароли не совпадают.',
    'integer'   => 'Поле «:attribute» должно быть числом.',
    'in'        => 'Выбрано недопустимое значение поля «:attribute».',
    'current_password' => 'Неверный пароль.',

    'attributes' => [
        'name'     => 'имя',
        'email'    => 'почта или логин',
        'password' => 'пароль',
        'message'  => 'сообщение',
        'title'    => 'название',
    ],
];
