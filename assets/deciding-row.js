/*
 * Finds the form row that decides another one: the row marked data-gv-field="<field>" that
 * gridview-conditional and gridview-dependent-select listen to.
 *
 * The rows of a form may sit in a layout (columns, fieldsets), so the deciding row is looked up
 * from the nearest enclosing element outwards, and the first one found wins. A row of the same
 * collection row comes first, so that each row of a collection follows its own field even when
 * the two fields are in different columns; failing that, the nearest one anywhere in the form.
 */
const COLLECTION_ROW = '[data-gridview-collection-target="row"]';

const collectionRowOf = (element) => element.parentElement?.closest(COLLECTION_ROW) ?? null;

export function findDecidingRow(element, field) {
    const selector = `[data-gv-field="${CSS.escape(field)}"]`;
    const form = element.closest('form');
    const ownRow = collectionRowOf(element);
    const nearest = (accept) => {
        for (let ancestor = element.parentElement; ancestor; ancestor = ancestor.parentElement) {
            const match = [...ancestor.querySelectorAll(selector)].find((row) => row !== element && accept(row));
            if (match || ancestor === form) {
                return match ?? null;
            }
        }

        return null;
    };

    return nearest((row) => collectionRowOf(row) === ownRow) ?? nearest(() => true);
}
