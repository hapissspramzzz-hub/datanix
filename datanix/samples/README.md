# Sample dataset attribution

`uci-online-retail.csv` contains exactly 5,000 authentic transaction rows selected at evenly spaced positions across the original UCI spreadsheet. The selection is deterministic and keeps the original headers and transaction values; timestamps are normalized to ISO text for safe import. It is **not synthetic, live or current-month sales data**.

- **Original source:** Chen, D. (2015). *Online Retail* [Dataset]. UCI Machine Learning Repository. https://doi.org/10.24432/C5BW33
- **Dataset page:** https://archive.ics.uci.edu/dataset/352/online+retail
- **Full source coverage:** 2010-12-01 through 2011-12-09; `UnitPrice` is in British pounds sterling (GBP). The app displays the actual range in the imported sample; it can differ from the full collection.
- **License:** Creative Commons Attribution 4.0 International (CC BY 4.0). Attribution is required; users may share/adapt under its terms.
- **Sample policy:** selected values are source records, not generated rows. The sample retains cancellation transactions (`InvoiceNo` beginning with `C`) and missing cells from the source. A derived `Quantity × UnitPrice` is a gross line amount; cancellations/returns must be interpreted separately.
