import "clsx";
import { T as TransactionListPage } from "../../../../chunks/TransactionListPage.js";
import { p as purchaseRows } from "../../../../chunks/fixtures.js";
function _page($$renderer) {
  TransactionListPage($$renderer, { mode: "purchases", rows: purchaseRows });
}
export {
  _page as default
};
