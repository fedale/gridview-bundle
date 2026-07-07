# fedale/gridview-bundle

> **Application-grade data grids for Symfony.**
>
> Build searchable, sortable, editable data grids anywhere in your application — without adopting an admin framework.

Gridview is an **embeddable data-grid platform** for Symfony.

It brings together everything expected from a modern business grid—filtering, sorting, CRUD, export, real-time updates and internationalization—while remaining just another component of your application.

Unlike an admin generator, Gridview never takes ownership of your pages.

It integrates into the application you already have.

---

## Why Gridview exists

Symfony developers generally face two very different options.

On one side there are **admin frameworks**, such as EasyAdmin, designed to build complete administration panels.

On the other there are lightweight datagrid libraries that simply render a Doctrine query as an HTML table.

Both solve their own problem well.

Gridview exists for the space between them.

It provides a fully featured data grid that can be embedded into **any Symfony page**, whether that's a customer portal, an ERP, a CRM, a reporting dashboard or a traditional back office.

Instead of generating an application, it enhances one.

---

## Philosophy

Gridview follows a simple principle:

> **Your application owns the page.
> Gridview owns the data grid.**

Layouts remain yours.

Navigation remains yours.

Security remains yours.

Controllers remain yours.

Gridview focuses on one responsibility: presenting and manipulating structured data.

This separation keeps the bundle flexible enough to integrate into existing applications instead of forcing applications to adapt to the bundle.

---

## At a glance

✔ Embeddable anywhere

✔ CRUD-ready

✔ Search & filtering

✔ Multi-column sorting

✔ Pagination

✔ Batch actions

✔ Export (CSV, Excel, PDF)

✔ Mercure real-time updates

✔ Client-side internationalization

✔ Theme support

✔ Twig-first rendering

✔ Doctrine and custom data providers

---

## Is Gridview the right tool?

| Your goal                                             | Recommended solution |
| ----------------------------------------------------- | -------------------- |
| Build a complete `/admin` backend                     | EasyAdmin            |
| Render a lightweight Doctrine table                   | kibatic/datagrid     |
| Embed a rich data grid inside an existing application | **fedale/gridview**  |

These tools are complementary rather than direct competitors.

Gridview deliberately occupies the middle ground: richer than a simple datagrid library, more reusable than an admin framework.

---

## Typical applications

Gridview is particularly suited to software where the grid is **part of the interface**, not the whole interface.

Examples include:

* customer portals
* ERP systems
* CRM software
* SaaS dashboards
* reporting tools
* intranet applications
* logistics software
* healthcare systems
* educational platforms
* embedded administration pages

If your application has its own identity and simply needs powerful data management, Gridview was designed for that scenario.

---

# Installation

```bash
composer require fedale/gridview-bundle
```

Requirements:

* PHP 8.1+
* Symfony 6.4+

Symfony Flex automatically enables the bundle.

---

# A first grid

Every Gridview starts with two concepts:

* **where the data comes from**
* **how that data should be presented**

```php
$columns = [
    'id',
    'code',
    'username',
];

$dataProvider = [
    'model' => Customer::class,
];

$grid = $this->createGridviewBuilder()
    ->setDataProvider($dataProvider)
    ->setColumns($columns)
    ->renderGridview();

return $grid->renderGrid(
    '@FedaleGridview/gridview/index.html.twig'
);
```

This produces a working grid.

From there you progressively add sorting, searching, pagination, custom rendering, CRUD operations, exports and everything else your application requires.

---

# Architecture

Gridview is intentionally composed of small, independent building blocks.

| Component    | Responsibility                     |
| ------------ | ---------------------------------- |
| DataProvider | Retrieves data from any source     |
| SearchModel  | Handles filtering                  |
| Column       | Defines presentation and behaviour |
| Renderer     | Produces HTML                      |
| Control      | Handles editing                    |
| Export       | Generates downloadable files       |
| Theme        | Controls presentation              |
| Translator   | Client-side localization           |

Each layer can be customized independently.

---

# Core features

## Data

* Doctrine ORM support
* custom DataProviders
* server-side filtering
* multi-column sorting
* pagination
* batch operations

## Rendering

* Twig templates
* custom cell renderers
* reusable templates
* responsive layouts
* dark mode
* multiple themes

## Editing

* CRUD
* inline editing
* detail views
* context-aware visibility
* reusable controls

## Integration

* Symfony Forms
* Twig
* Translation component
* Mercure
* Maker commands

---

# Design principles

Gridview intentionally favors **explicit configuration** over hidden conventions.

Almost every aspect of the grid is configurable.

Columns describe presentation.

Controls describe editing.

Filters describe searching.

Because these concerns stay separate, applications remain predictable and easier to extend over time.

---

# Documentation

Detailed documentation is available for:

* Data Providers
* Search Models
* Column types
* Controls
* Filters
* Export
* Themes
* Internationalization
* Mercure integration
* Maker commands

See the **docs/** directory.

---

# Inspiration

Gridview was inspired by the Yii2 GridView ecosystem, particularly Kartik's GridView, while embracing modern Symfony practices.

The goal is to provide Symfony applications with an embeddable, framework-native data-grid platform that integrates naturally with Twig, Forms, Translation and the rest of the Symfony ecosystem.