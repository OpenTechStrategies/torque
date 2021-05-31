/*@nomin*/
function addEditButtonListeners () {
    $(".torque-edit-button.paragraph").click(handleParagraphEdit);
    $(".torque-edit-button.list").click(handleListEdit);

    const [sheetName, , key] = $("#page-info")
        .data("location")
        .replace(".mwiki", "")
        .split("/");
    window.sheetName = sheetName;
    window.wikiKey = $("#page-info").data("wiki-key");
    window.key = key;
}

$(document).ready(() => {
    if (window.userRights.includes("torquedataconnect-edit")) {
        $('body').toggleClass('show-edit');
        addEditButtonListeners();
    }
});

async function submitEdit (field, value) {
    const postData = {
        field: field,
        newValue: value,
        sheetName: window.sheetName,
        wikiKey: window.wikiKey,
        key: window.key,
        title: mw.config.values.wgTitle,
    };
    try {
        // Gets CORS token for POST
        const corsResult = await $.ajax({
            type: "GET",
            url: `${mw.config.values.wgScriptPath}/api.php?action=query&format=json&meta=tokens`,
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
        $('.torque-edit-button').css("visibility", "visible");
        addEditButtonListeners();
    } catch (error) {
        console.error(error);
    }
}

async function getFieldValue (field) {
    const actionName = "torquedataconnect";
    const path = "/sheets/" +
        window.sheetName +
        "/rows/" +
        window.key +
        "/fields/" +
        field;
    const results = await $.ajax({
        type: "GET",
        url: `${mw.config.values.wgScriptPath}/api.php`,
        data: {
            action: actionName,
            format: "json",
            wikiKey: window.wikiKey,
            path: path
        }
    })
    return results.result;
}

const textArea = (v) => $(`<textarea name="" type="text">${v}</textarea>`);
// Returns a jquery cancel and save button side-by-side
const saveButtons = (fieldName, originalValue, type, onCancel, onSave) => {
    const cancelBtn = $('<span class="torque-save-cancel">Cancel</span>');
    cancelBtn.data("original", originalValue);
    cancelBtn.click({ type }, onCancel);
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

const handleEdit = async (e, handleSave, type) => {
    const editButtonClicked = $(e.currentTarget);
    const field = editButtonClicked.data("field");
    const elementToEdit = editButtonClicked.prev('.editable');

    const html = elementToEdit[0].outerHTML;
    const wikitext = await getFieldValue(field);

    const newInput = textArea(wikitext);

    elementToEdit.replaceWith(newInput);
    editButtonClicked.replaceWith(
        saveButtons(
            field,
            html,
            type,
            handleSaveCancel,
            handleSave
        )
    );

    newInput[0].style.height = `${newInput[0].scrollHeight}px`;
}

const substituteValue = (sibling, target, newValue, field, type) => {
    sibling.replaceWith(newValue);
    const editBtn = editButton(type, field);
    editBtn.click(handleParagraphEdit);
    target.replaceWith(editBtn);
};

const handleSaveCancel = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = target.data("original");
    const field = target.next().data("field");
    substituteValue(sibling, target.parent(), newValue, field, e.data.type);
};

// Paragraph event listeners
const handleParagraphEdit = async (e) => {
    handleEdit(e, handleParagraphSave, "paragraph");
};

const handleParagraphSave = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = sibling[0].value;
    const field = target.data("field");
    submitEdit(field, newValue);
};

// List event listeners
const handleListEdit = async (e) => {
    handleEdit(e, handleListSave, "list");
};

const handleListSave = (e) => {
    const target = $(e.target);
    const sibling = $(e.target).parent().prev();
    const newValue = sibling[0].value;
    const field = target.data("field");
    submitEdit(field, newValue.split("\n"));
};