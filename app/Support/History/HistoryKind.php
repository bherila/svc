<?php

namespace App\Support\History;

enum HistoryKind: string
{
    case Company = 'company';
    case Person = 'person';
    case Project = 'project';
    case Task = 'task';
    case TaskList = 'task_list';
    case Subtask = 'subtask';
    case Comment = 'comment';
    case Note = 'note';
    case Discussion = 'discussion';
    case Invoice = 'invoice';
    case InvoiceLine = 'invoice_line';
    case Payment = 'payment';
    case Time = 'time';
    case Expense = 'expense';
    case File = 'file';
    case Activity = 'activity';
    case Delivery = 'delivery';
    case Change = 'change';
    case Recurring = 'recurring';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company', self::Person => 'Person', self::Project => 'Project',
            self::Task => 'Task', self::TaskList => 'Task list', self::Subtask => 'Subtask',
            self::Comment => 'Comment', self::Note => 'Note', self::Discussion => 'Discussion',
            self::Invoice => 'Invoice', self::InvoiceLine => 'Invoice item', self::Payment => 'Payment',
            self::Time => 'Time', self::Expense => 'Expense', self::File => 'File',
            self::Activity => 'Activity', self::Delivery => 'Email delivery', self::Change => 'Change',
            self::Recurring => 'Recurring invoice', self::Reference => 'Reference',
        };
    }
}
