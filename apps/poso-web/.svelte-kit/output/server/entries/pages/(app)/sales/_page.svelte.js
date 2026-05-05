import "clsx";
import { T as TransactionListPage } from "../../../../chunks/TransactionListPage.js";
import { s as salesRows } from "../../../../chunks/fixtures.js";
function _page($$renderer) {
  TransactionListPage($$renderer, { mode: "sales", rows: salesRows });
}
export {
  _page as default
};
