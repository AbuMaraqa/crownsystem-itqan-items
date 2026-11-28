{{-- مظهر كل المطبوعات (core::pdf.document): جداول رمادية بحدود خفيفة، صف عنوان للاصناف الفرعية،
     مربعات المجاميع، الملاحظات والتواقيع. غيّر المظهر هنا فقط. --}}
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'tajawal', sans-serif;
        direction: rtl;
        text-align: right;
        font-size: 10pt;
        color: #1f1f1f;
    }

    .text-end {
        text-align: left;
    }

    .text-center,
    .num {
        text-align: center;
    }

    .ltr {
        direction: ltr;
    }

    /* الترويسة */
    table.letterhead {
        width: 100%;
        border-collapse: collapse;
    }

    table.letterhead td {
        vertical-align: middle;
    }

    .letterhead .company {
        font-size: 17pt;
        color: #5b6166;
    }

    table.contacts {
        border-collapse: collapse;
        margin-top: 1mm;
    }

    table.contacts td {
        font-size: 8.5pt;
        color: #3a3f43;
        padding: 0 2mm;
        direction: ltr;
        text-align: left;
    }

    table.contacts td.sep {
        border-right: 0.8pt solid #3a3f43;
    }

    .doc-title {
        text-align: center;
        font-size: 14pt;
        font-weight: bold;
        margin-top: 4mm;
        margin-bottom: 2mm;
    }

    /* مربع البيانات: الطرف يمينا، والتواريخ والارقام يسارا */
    div.info-box {
        border: 0.8pt solid #1f1f1f;
    }

    table.info-box {
        width: 100%;
        border-collapse: collapse;
    }

    table.info-box td {
        vertical-align: top;
        padding: 1.5mm 3mm;
    }

    table.info-box .party-label {
        font-size: 9.5pt;
    }

    table.info-box .party-name {
        font-size: 10.5pt;
        padding-bottom: 1.5mm;
    }

    table.meta {
        width: 100%;
        border-collapse: collapse;
    }

    table.meta td {
        padding: 0.8mm 1mm;
        font-size: 9.5pt;
        vertical-align: top;
    }

    table.meta td.label {
        white-space: nowrap;
        width: 22mm;
    }

    /* جداول البنود */
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4mm;
    }

    table.items th {
        background-color: #f0f0f0;
        color: #1f1f1f;
        border: 0.6pt solid #cfcfcf;
        padding: 1.5mm 1.2mm;
        font-size: 9pt;
        font-weight: normal;
        text-align: center;
        vertical-align: middle;
    }

    table.items td {
        border: 0.6pt solid #d6d6d6;
        padding: 2mm 1.2mm;
        font-size: 9.5pt;
        text-align: center;
        vertical-align: middle;
    }

    table.items td.start,
    table.items th.start {
        text-align: right;
    }

    table.items tr.main-row td {
        background-color: #ffffff;
    }

    table.items tr.piece-head td {
        background-color: #f0f0f0;
        font-size: 8.5pt;
        padding: 1.2mm;
    }

    table.items tr.piece-row td {
        font-size: 9pt;
    }

    table.items tr.total-row td {
        background-color: #f0f0f0;
        font-weight: bold;
    }

    table.items td.big {
        font-weight: bold;
        font-size: 10.5pt;
    }

    .piece-thumb {
        height: 9mm;
        max-width: 22mm;
        vertical-align: middle;
    }

    h2.section-title {
        font-size: 11pt;
        background-color: #f0f0f0;
        border: 0.6pt solid #cfcfcf;
        padding: 1.5mm 2mm;
        margin: 2mm 0 0;
    }

    /* المجاميع: عنوان بجانب مربع القيمة */
    table.totals {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1mm;
    }

    table.totals td {
        padding: 1mm 1.5mm;
        vertical-align: middle;
        font-size: 10pt;
    }

    table.totals td.value,
    table.totals td.grand {
        border: 0.8pt solid #555;
        text-align: center;
        direction: ltr;
        width: 17%;
        padding: 1.5mm;
    }

    table.totals td.grand {
        font-weight: bold;
        border: 1.2pt solid #1f1f1f;
    }

    table.totals td.label {
        width: 16%;
        white-space: nowrap;
        text-align: left;
    }

    .notes {
        margin-top: 5mm;
    }

    .notes .label {
        margin-bottom: 1mm;
    }

    .notes .body {
        color: #333;
    }

    table.signatures {
        width: 100%;
        margin-top: 10mm;
        border-collapse: collapse;
    }

    table.signatures td {
        padding: 2mm 0;
        font-size: 10pt;
        white-space: nowrap;
    }

    table.signatures td.dots {
        color: #333;
        text-align: center;
    }

    .pick {
        direction: ltr;
        font-weight: bold;
    }

    .status {
        display: inline-block;
        padding: 0.5mm 2mm;
        border: 0.6pt solid #9ca3af;
        font-size: 8.5pt;
    }
</style>
