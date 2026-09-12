<?php

namespace App\Knowledge\Contracts;

use App\Knowledge\DocumentDraft;
use App\Models\KnowledgeSource;

/**
 * Источник знаний.
 *
 * Каждый новый тип источника — это один класс, который умеет три вещи:
 * сказать, какие поля показать админу в форме; проверить подключение;
 * отдать документы. Больше нигде в приложении править ничего не нужно —
 * регистрируем класс в ConnectorRegistry, и он появляется в админке.
 */
interface Connector
{
    /** Машинный ключ, он же значение колонки knowledge_sources.type. */
    public static function key(): string;

    /** Название для админки. */
    public static function label(): string;

    /** Пояснение под названием: что это за источник и что туда писать. */
    public static function help(): string;

    /**
     * Описание полей формы.
     *
     * @return array<int, array{name:string,label:string,type?:string,required?:bool,help?:string,placeholder?:string,options?:array,default?:mixed,secret?:bool}>
     */
    public static function fields(): array;

    public function __construct(KnowledgeSource $source);

    /**
     * Проверка подключения кнопкой "Проверить" в админке.
     *
     * @return array{ok:bool,message:string}
     */
    public function test(): array;

    /**
     * Документы источника. Генератор, а не массив: источников
     * с десятками тысяч файлов мы в память целиком не поднимаем.
     *
     * @return iterable<DocumentDraft>
     */
    public function documents(): iterable;
}
