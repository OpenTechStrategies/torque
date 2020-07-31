/*@nomin*/
function addEditButtonListeners () {
    $(".torque-edit-button.paragraph").click(handleParagraphEdit);
    $(".torque-edit-button.list").click(handleListEdit);

    const [sheetName, , key] = $("#page-info")
        .data("location")
        .replace(".mwiki", "")
        .split("/");
    window.sheetName = sheetName;
    window.key = key;
}

$(document).ready(() => {
    addEditButtonListeners();
});

async function submitEdit (field, value, callback) {
    const postData = {
        newValues: JSON.stringify({ [field]: value }),
        sheetName: window.sheetName,
        key: window.key,
        title: mw.config.values.wgTitle,
    };
    try {
        // Gets CORS token for POST
        const corsResult = await $.ajax({
            type: "GET",
            url: "/lfc/api.php?action=query&format=json&meta=tokens",
            dataType: "json",
        });

        const token = encodeURIComponent(corsResult.query.tokens.csrftoken);
        const actionName = "torquedataconnectsubmitedit";
        const results = await $.ajax({
            type: "POST",
            url: `${mw.config.values.wgScriptPath}/api.php?action=${actionName}&format=json&token=${token}`,
            data: postData,
            dataType: "json",
        });
        $('.torque-wrapper').replaceWith(results.html);
        addEditButtonListeners();
    } catch (error) {
        console.error(error);
    }
}

const textArea = (v) => $(`<textarea name="" type="text">${v}</textarea>`);
// Returns a jquery cancel and save button side-by-side
const saveButtons = (fieldName, originalValue, onCancel, onSave) => {
    const cancelBtn = $('<span class="torque-save-cancel">Cancel</span>');
    cancelBtn.data("original", originalValue);
    cancelBtn.click(onCancel);
    const saveBtn = $('<span class="torque-save">Save</span>');
    saveBtn.data("field", fieldName);
    saveBtn.click(onSave);
    return $(`<div id="${fieldName}" class="torque-save-wrapper"></div>`)
        .append(cancelBtn)
        .append(saveBtn);
};
const editButton = (type, field) => {
    return $(
        `<div class="torque-edit-button ${type}"><div class="torque-edit">edit</div></div>`
    )
        .data("type", type)
        .data("field", field);
};

// Paragraph event listeners
const handleParagraphEdit = (e) => {
    const target = $(e.currentTarget);
    const sibling = $(e.currentTarget.previousSibling);
    const text = sibling[0].innerText;
    const field = target.data("field");
    const newInput = textArea(text)
    sibling.replaceWith(newInput);
    target.replaceWith(
        saveButtons(
            field,
            text,
            handleParagraphSaveCancel,
            handleParagraphSave
        )
    );

    newInput[0].style.height = `${newInput[0].scrollHeight}px`;
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
};

const substituteParagraphValue = (sibling, target, newValue, field) => {
    sibling.replaceWith(`<p>${newValue}</p>`);
    const editBtn = editButton("paragraph", field);
    editBtn.click(handleParagraphEdit);
    target.replaceWith(editBtn);
};

const handleParagraphSaveCancel = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = target.data("original");
    const field = target.next().data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    console.log(field);
    substituteParagraphValue(sibling, target.parent(), newValue, field);
};

const handleParagraphSave = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = sibling[0].value;
    const field = target.data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    submitEdit(field, newValue);
    substituteParagraphValue(sibling, target.parent(), newValue, field);
};

// Unordered list event listeners
const handleListEdit = (e) => {
    const clickedButton = $(e.currentTarget);
    let dataField = $(e.currentTarget).prev();
    let listElements = [];
    while (dataField[0].nodeName == "UL") {
        listElements.unshift(dataField);
        dataField = dataField.prev();
    }

    const val = listElements.map((e) => e[0].querySelector('.editable').innerText);
    const field = clickedButton.data("field");

    for (let e of listElements) {
        e.remove();
    }

    const newInput = textArea(val.join("\n")).add(
        saveButtons(
            field,
            listElements.map(e => e.find('li').html()).join('\n'),
            handleListSaveCancel,
            handleListSave
        )
    )

    clickedButton.replaceWith(newInput);
    newInput[0].style.height = `${newInput[0].scrollHeight}px`;
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
};

const substituteListValue = (sibling, target, newValue, field) => {
    let listElements = "";
    for (let l of newValue.split("\n")) {
        listElements += `<ul><li>${l}</li></ul>`;
    }
    sibling.replaceWith(listElements);
    const btn = $(editButton("list", field));
    btn.click(handleListEdit);
    target.replaceWith(btn);
};

const handleListSaveCancel = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = target.data("original");
    const field = target.next().data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    substituteListValue(sibling, target.parent(), newValue, field);
};

const handleListSave = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = sibling[0].value;
    const field = target.data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    submitEdit(field, newValue.split("\n"));
    substituteListValue(sibling, target.parent(), newValue, field);
};

// Paragraph event listeners
const handleInlineEdit = (e) => {
    const target = $(e.currentTarget);
    const sibling = $(e.currentTarget.previousSibling);
    const text = sibling[0].innerText;
    const field = target.data("field");
    const newInput = textArea(text)
    sibling.replaceWith(newInput);
    target.replaceWith(
        saveButtons(
            field,
            text,
            handleParagraphSaveCancel,
            handleParagraphSave
        )
    );

    newInput[0].style.height = `${newInput[0].scrollHeight}px`;
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
};

const substituteInlineValue = (sibling, target, newValue, field) => {
    sibling.replaceWith(`<p>${newValue}</p>`);
    const editBtn = editButton("paragraph", field);
    editBtn.click(handleParagraphEdit);
    target.replaceWith(editBtn);
};

const handleInlineSaveCancel = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = target.data("original");
    const field = target.next().data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    console.log(field);
    substituteParagraphValue(sibling, target.parent(), newValue, field);
};

const handleInlineSave = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = sibling[0].value;
    const field = target.data("field");
    console.log($(`#${$.escapeSelector(field)} > .torque-save`).data("field"));
    submitEdit(field, newValue);
    substituteParagraphValue(sibling, target.parent(), newValue, field);
};