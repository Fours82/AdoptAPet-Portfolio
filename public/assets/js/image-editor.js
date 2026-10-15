const IMAGE_EDITOR_MAX_BYTES = 5 * 1024 * 1024;
const IMAGE_EDITOR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const editedImageSelections = new Map();
const editedImageBatches = new Map();
let activeImageEditor = null;
let activeCropPointer = null;
const CROP_MIN_PERCENT = 5;
const CROP_MIN_DISPLAY_PIXELS = 36;

function imageUploadControl(
  inputId,
  previewId,
  label = 'Image',
  existingPath = '',
  handlers = {},
) {
  let preview = existingPath
    ? `<img src="${esc(existingPath)}" alt="Current ${esc(label.toLowerCase())}">`
    : '<span>No image selected</span>';
  let changeHandler =
      handlers.change || `openImageEditor(event,'${previewId}')`,
    editHandler =
      handlers.edit || `reopenImageEditor('${inputId}','${previewId}')`,
    removeHandler =
      handlers.remove || `removeEditedImage('${inputId}','${previewId}')`;
  return `<div class="image-upload"><label for="${inputId}">${esc(label)} (optional)</label><input id="${inputId}" class="input" type="file" accept="image/jpeg,image/png,image/webp" onchange="${changeHandler}"><p class="field-help">JPEG, PNG or WebP · maximum 5 MB. The original is ready immediately; editing is optional.</p><div id="${previewId}" class="preview image-upload-preview">${preview}</div><div class="editor-controls"><button id="${inputId}-edit" type="button" onclick="${editHandler}" aria-label="Edit selected ${esc(label.toLowerCase())}">Edit Image</button><button type="button" onclick="${removeHandler}" aria-label="Remove selected ${esc(label.toLowerCase())}">Remove Image</button></div></div>`;
}

function imageBatchUploadControl(
  inputId,
  queueId,
  label = 'Images',
  maximum = 1,
  existingCount = 0,
  totalMaximum = maximum,
) {
  let limit = Math.max(1, Number(maximum) || 1),
    base = Math.max(0, Number(existingCount) || 0),
    total = Math.max(base + limit, Number(totalMaximum) || limit);
  return `<div class="image-upload image-batch-upload"><label>${esc(label)} (optional)</label><input id="${inputId}" class="image-batch-file-input" type="file" accept="image/jpeg,image/png,image/webp" multiple data-queue-id="${queueId}" data-maximum="${limit}" data-existing-count="${base}" data-total-maximum="${total}" onchange="openImageBatch(event,'${queueId}',${limit},${base},${total})"><div class="image-batch-picker"><button id="${inputId}-add" type="button" class="btn outline sm" onclick="document.getElementById('${inputId}').click()">+ Add Images</button><strong id="${inputId}-counter" class="image-batch-counter" aria-live="polite">${base} / ${total} images</strong></div><p class="field-help">Choose one or several JPEG, PNG or WebP images · maximum 5 MB each. Originals are ready immediately; editing is optional.</p><div id="${queueId}" class="image-batch-queue"><span>No new images selected</span></div></div>`;
}

function batchImageToken() {
  return `img${Date.now().toString(36)}${Math.random().toString(36).slice(2, 9)}`;
}

function releaseBatchItem(item) {
  if (item?.sourceUrl) URL.revokeObjectURL(item.sourceUrl);
  if (item?.previewUrl && item.previewUrl !== item.sourceUrl)
    URL.revokeObjectURL(item.previewUrl);
}

function clearEditedImageBatch(inputId) {
  let batch = editedImageBatches.get(inputId);
  batch?.items?.forEach(releaseBatchItem);
  editedImageBatches.delete(inputId);
  let input = $(`#${inputId}`);
  if (input) input.value = '';
  let queueId = batch?.queueId || input?.dataset.queueId,
    queue = queueId ? $(`#${queueId}`) : null;
  if (queue) queue.innerHTML = '<span>No new images selected</span>';
  updateImageBatchControls(inputId, null);
}

function updateImageBatchControls(
  inputId,
  batch = editedImageBatches.get(inputId),
) {
  let input = $(`#${inputId}`),
    base = Number(batch?.existingCount ?? input?.dataset.existingCount ?? 0),
    maximum = Number(batch?.maximum ?? input?.dataset.maximum ?? 1),
    total = Number(
      batch?.totalMaximum ?? input?.dataset.totalMaximum ?? maximum,
    ),
    selected = batch?.items?.length || 0,
    counter = $(`#${inputId}-counter`),
    addButton = $(`#${inputId}-add`);
  if (counter) counter.textContent = `${base + selected} / ${total} images`;
  if (addButton) {
    let full = selected >= maximum;
    addButton.disabled = full;
    addButton.textContent = full
      ? `Maximum ${total} images reached`
      : '+ Add Images';
  }
}

function renderImageBatchQueue(inputId) {
  let batch = editedImageBatches.get(inputId),
    queue = batch ? $(`#${batch.queueId}`) : null;
  if (!queue) return;
  if (!batch.items.length)
    queue.innerHTML = '<span>No new images selected</span>';
  else
    queue.innerHTML = batch.items
      .map(
        (item, index) =>
          `<article class="image-batch-item"><img src="${esc(item.previewUrl || item.sourceUrl)}" alt="Preview of ${esc(item.filename)}"><div class="image-batch-meta"><strong>Image ${index + 1}</strong><span title="${esc(item.filename)}">${esc(item.filename)}</span><small class="ready">${item.applied ? 'Ready (edited)' : 'Ready (original)'}</small></div><div class="image-batch-actions"><button type="button" class="btn outline sm" onclick="editBatchImage('${inputId}','${item.token}')">${item.applied ? 'Edit again' : 'Edit'}</button><button type="button" class="link-btn danger" onclick="removeBatchImage('${inputId}','${item.token}')" aria-label="Remove ${esc(item.filename)}">Remove</button></div></article>`,
      )
      .join('');
  updateImageBatchControls(inputId, batch);
}

function openImageBatch(
  event,
  queueId,
  maximum,
  existingCount = 0,
  totalMaximum = maximum,
) {
  let input = event.target,
    files = Array.from(input.files || []),
    limit = Math.max(1, Number(maximum) || 1);
  if (!files.length) {
    input.value = '';
    return;
  }
  let batch = editedImageBatches.get(input.id),
    selected = batch?.items?.length || 0,
    remaining = limit - selected;
  if (files.length > remaining) {
    input.value = '';
    toast(
      remaining
        ? `You can add ${remaining} more image${remaining === 1 ? '' : 's'}.`
        : `The maximum of ${Number(totalMaximum) || limit} images has been reached.`,
    );
    return;
  }
  let error = files.map(validateSelectedImage).find(Boolean);
  if (error) {
    input.value = '';
    toast(error);
    return;
  }
  let items = files.map((file) => ({
    token: batchImageToken(),
    sourceFile: file,
    uploadFile: file,
    sourceUrl: URL.createObjectURL(file),
    previewUrl: '',
    filename: file.name,
    mimeType: file.type,
    applied: false,
  }));
  if (!batch) {
    batch = {
      inputId: input.id,
      queueId,
      maximum: limit,
      existingCount: Math.max(0, Number(existingCount) || 0),
      totalMaximum: Math.max(
        Number(totalMaximum) || limit,
        (Number(existingCount) || 0) + limit,
      ),
      items: [],
    };
    editedImageBatches.set(input.id, batch);
  }
  batch.items.push(...items);
  input.value = '';
  renderImageBatchQueue(input.id);
}

function editBatchImage(inputId, token) {
  let batch = editedImageBatches.get(inputId),
    item = batch?.items?.find((candidate) => candidate.token === token);
  if (!item) return toast('That selected image is no longer available.');
  launchImageEditor(
    inputId,
    batch.queueId,
    item.uploadFile || item.sourceFile,
    token,
    item.sourceFile,
  );
}

function removeBatchImage(inputId, token) {
  let batch = editedImageBatches.get(inputId);
  if (!batch) return;
  let index = batch.items.findIndex((item) => item.token === token);
  if (index < 0) return;
  if (activeImageEditor?.batchToken === token) closeImageEditor();
  releaseBatchItem(batch.items[index]);
  batch.items.splice(index, 1);
  renderImageBatchQueue(inputId);
}

function appendEditedImageBatch(formData, inputId, field = 'images[]') {
  let batch = editedImageBatches.get(inputId);
  if (!batch?.items?.length) return 0;
  batch.items.forEach((item) => {
    let file = item.uploadFile || item.sourceFile;
    formData.append(field, file, file.name || item.filename);
  });
  return batch.items.length;
}

function validateSelectedImage(file) {
  if (!file) return 'Select an image first.';
  if (!IMAGE_EDITOR_TYPES.includes(file.type))
    return 'Only JPEG, PNG, and WebP images are allowed.';
  if (file.size < 1 || file.size > IMAGE_EDITOR_MAX_BYTES)
    return 'Images must be no larger than 5 MB.';
  return '';
}

function openImageEditor(event, previewId) {
  let input = event.target,
    file = input.files?.[0],
    error = validateSelectedImage(file);
  if (error) {
    input.value = '';
    toast(error);
    return false;
  }
  clearEditedImageSelection(input.id);
  let sourceUrl = URL.createObjectURL(file),
    preview = $(`#${previewId}`);
  editedImageSelections.set(input.id, {
    filename: file.name,
    mimeType: file.type,
    sourceFile: file,
    uploadFile: file,
    sourceUrl,
    previewUrl: '',
    applied: false,
  });
  if (preview)
    preview.innerHTML = `<img src="${sourceUrl}" alt="Selected image preview"><small>Ready (original)</small>`;
  return true;
}

function reopenImageEditor(inputId, previewId) {
  let selection = editedImageSelections.get(inputId),
    input = $(`#${inputId}`),
    file = selection?.uploadFile || selection?.sourceFile || input?.files?.[0];
  if (!file) return toast('Select an image first.');
  launchImageEditor(
    inputId,
    previewId,
    file,
    '',
    selection?.sourceFile || file,
  );
  return true;
}

function storedImageFilename(url, mimeType, fallbackName = 'image') {
  let extension =
      mimeType === 'image/png'
        ? 'png'
        : mimeType === 'image/webp'
          ? 'webp'
          : 'jpg',
    urlName = '';
  try {
    urlName = decodeURIComponent(
      new URL(url, window.location.href).pathname.split('/').pop() || '',
    );
  } catch (error) {}
  let base =
    String(urlName || fallbackName)
      .replace(/\.[^.]+$/, '')
      .replace(/[^A-Za-z0-9_-]/g, '_')
      .slice(0, 100) || 'image';
  return `${base}.${extension}`;
}

async function openStoredImageEditor(
  inputId,
  previewId,
  imagePath,
  fallbackName = 'image',
) {
  let url = new URL(String(imagePath || ''), window.location.href);
  if (url.origin !== window.location.origin)
    throw new Error('Only same-origin saved images can be edited.');
  let response = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
  });
  if (!response.ok) throw new Error('The saved image could not be loaded.');
  let blob = await response.blob(),
    mimeType = String(blob.type || response.headers.get('Content-Type') || '')
      .split(';')[0]
      .trim()
      .toLowerCase(),
    file = new File([blob], storedImageFilename(url, mimeType, fallbackName), {
      type: mimeType,
      lastModified: Date.now(),
    }),
    error = validateSelectedImage(file);
  if (error) throw new Error(error);
  launchImageEditor(inputId, previewId, file, '', file);
  return true;
}

function launchImageEditor(
  inputId,
  previewId,
  file,
  batchToken = '',
  resetFile = file,
) {
  closeImageEditor();
  let themeClass = document.querySelector('.public-site-shell')
    ? ' public-image-editor'
    : '';
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="image-editor-modal" class="image-editor-modal${themeClass}" role="dialog" aria-modal="true" aria-labelledby="image-editor-title"><section class="panel image-editor-panel"><div class="section-head"><div><h2 id="image-editor-title">Edit Image</h2><p class="muted">Drag the crop box to move it, or drag a handle to resize it.</p></div><button type="button" class="link-btn" onclick="closeImageEditor()" aria-label="Close image editor">Close</button></div><div class="image-editor-workspace"><div class="image-canvas-wrap"><canvas id="image-editor-canvas" aria-label="Editable image preview"></canvas><div id="image-crop-overlay" class="image-crop-overlay" aria-hidden="true" onpointerdown="startCropPointer(event,'move')"><span class="crop-resize-handle north-west" onpointerdown="startCropPointer(event,'nw')"></span><span class="crop-resize-handle north" onpointerdown="startCropPointer(event,'n')"></span><span class="crop-resize-handle north-east" onpointerdown="startCropPointer(event,'ne')"></span><span class="crop-resize-handle east" onpointerdown="startCropPointer(event,'e')"></span><span class="crop-resize-handle south-east" onpointerdown="startCropPointer(event,'se')"></span><span class="crop-resize-handle south" onpointerdown="startCropPointer(event,'s')"></span><span class="crop-resize-handle south-west" onpointerdown="startCropPointer(event,'sw')"></span><span class="crop-resize-handle west" onpointerdown="startCropPointer(event,'w')"></span></div></div><div class="crop-controls"><label>Crop left <output id="crop-x-output">10%</output><input id="crop-x" type="range" min="0" max="95" step="0.1" value="10" oninput="updateCropOverlay()"></label><label>Crop top <output id="crop-y-output">10%</output><input id="crop-y" type="range" min="0" max="95" step="0.1" value="10" oninput="updateCropOverlay()"></label><label>Crop width <output id="crop-width-output">80%</output><input id="crop-width" type="range" min="5" max="100" step="0.1" value="80" oninput="updateCropOverlay()"></label><label>Crop height <output id="crop-height-output">80%</output><input id="crop-height" type="range" min="5" max="100" step="0.1" value="80" oninput="updateCropOverlay()"></label></div></div><div class="editor-toolbar" aria-label="Image editing controls"><button type="button" class="btn outline sm" onclick="cropEditorImage()">Crop to Selection</button><button type="button" class="btn outline sm" onclick="transformEditorImage('left')" aria-label="Rotate image left 90 degrees">Rotate Left</button><button type="button" class="btn outline sm" onclick="transformEditorImage('right')" aria-label="Rotate image right 90 degrees">Rotate Right</button><button type="button" class="btn outline sm" onclick="transformEditorImage('horizontal')">Flip Horizontal</button><button type="button" class="btn outline sm" onclick="transformEditorImage('vertical')">Flip Vertical</button><button type="button" class="btn outline sm" onclick="resetEditorImage()">Reset</button></div><div id="image-editor-message" class="validation" role="status" aria-live="polite"></div><div class="form-actions"><button type="button" class="btn" onclick="applyImageEdit()">Apply Edited Image</button><button type="button" class="btn outline" onclick="closeImageEditor()">Cancel</button></div></section></div>`,
  );
  let image = new Image(),
    url = URL.createObjectURL(file),
    resetImage = resetFile === file ? image : new Image(),
    resetUrl = resetFile === file ? '' : URL.createObjectURL(resetFile);
  activeImageEditor = {
    inputId,
    previewId,
    sourceFile: file,
    resetFile,
    objectUrl: url,
    resetUrl,
    mimeType: file.type,
    originalName: resetFile.name,
    image,
    resetImage,
    batchToken,
    cropDirty: false,
    onApply: null,
  };
  image.onload = () => {
    let canvas = $('#image-editor-canvas'),
      maxDimension = 4096,
      scale = Math.min(
        1,
        maxDimension / Math.max(image.naturalWidth, image.naturalHeight),
      );
    canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
    canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
    updateCropOverlay(false);
    $('#crop-x')?.focus();
  };
  image.onerror = () => {
    setImageEditorMessage(
      'The selected file could not be decoded as an image.',
    );
  };
  image.src = url;
  if (resetUrl) resetImage.src = resetUrl;
}

function setImageEditorMessage(message) {
  let target = $('#image-editor-message');
  if (target) target.textContent = message || '';
}

function closeImageEditor() {
  if (activeImageEditor?.objectUrl)
    URL.revokeObjectURL(activeImageEditor.objectUrl);
  if (activeImageEditor?.resetUrl)
    URL.revokeObjectURL(activeImageEditor.resetUrl);
  activeCropPointer = null;
  document.body.classList.remove('crop-pointer-active');
  activeImageEditor = null;
  $('#image-editor-modal')?.remove();
}

function clampCropValue(value, minimum, maximum) {
  return Math.min(maximum, Math.max(minimum, value));
}

function normaliseCropValues(
  values,
  minimumWidth = CROP_MIN_PERCENT,
  minimumHeight = CROP_MIN_PERCENT,
) {
  let width = clampCropValue(Number(values.width) || 100, minimumWidth, 100),
    height = clampCropValue(Number(values.height) || 100, minimumHeight, 100),
    x = clampCropValue(Number(values.x) || 0, 0, 100 - width),
    y = clampCropValue(Number(values.y) || 0, 0, 100 - height);
  return { x, y, width, height };
}

function cropValues() {
  let x = Number($('#crop-x')?.value || 0),
    y = Number($('#crop-y')?.value || 0),
    width = Number($('#crop-width')?.value || 100),
    height = Number($('#crop-height')?.value || 100);
  return normaliseCropValues({ x, y, width, height });
}

function setCropValues(values) {
  let next = normaliseCropValues(values),
    displayValues = {},
    overlay = $('#image-crop-overlay');
  for (let [key, value] of Object.entries(next)) {
    let input = $(`#crop-${key}`),
      output = $(`#crop-${key}-output`),
      display = Math.round(value * 10) / 10;
    displayValues[key] = display;
    if (input) input.value = String(display);
    if (output) output.textContent = `${display}%`;
  }
  displayValues = normaliseCropValues(displayValues);
  if (overlay) {
    overlay.style.left = `${displayValues.x}%`;
    overlay.style.top = `${displayValues.y}%`;
    overlay.style.width = `${displayValues.width}%`;
    overlay.style.height = `${displayValues.height}%`;
  }
  return displayValues;
}

function updateCropOverlay(markDirty = true) {
  setCropValues(cropValues());
  if (markDirty && activeImageEditor) activeImageEditor.cropDirty = true;
}

function startCropPointer(event, mode = 'move') {
  if (
    !activeImageEditor ||
    (event.pointerType === 'mouse' && event.button !== 0)
  )
    return;
  let canvas = $('#image-editor-canvas'),
    overlay = $('#image-crop-overlay');
  if (!canvas || !overlay || !canvas.width) return;
  event.preventDefault();
  event.stopPropagation();
  let rect = canvas.getBoundingClientRect(),
    values = cropValues();
  activeCropPointer = {
    pointerId: event.pointerId,
    mode,
    startX: event.clientX,
    startY: event.clientY,
    rect,
    values,
    target: event.currentTarget,
  };
  try {
    event.currentTarget.setPointerCapture(event.pointerId);
  } catch (error) {}
  overlay.classList.add('dragging');
  document.body.classList.add('crop-pointer-active');
}

function moveCropPointer(event) {
  let drag = activeCropPointer;
  if (!drag || event.pointerId !== drag.pointerId) return;
  event.preventDefault();
  let dx = ((event.clientX - drag.startX) / Math.max(1, drag.rect.width)) * 100,
    dy = ((event.clientY - drag.startY) / Math.max(1, drag.rect.height)) * 100,
    start = drag.values;
  if (drag.mode === 'move') {
    setCropValues({
      ...start,
      x: clampCropValue(start.x + dx, 0, 100 - start.width),
      y: clampCropValue(start.y + dy, 0, 100 - start.height),
    });
    if (activeImageEditor) activeImageEditor.cropDirty = true;
    return;
  }
  let left = start.x,
    right = start.x + start.width,
    top = start.y,
    bottom = start.y + start.height,
    minWidth = Math.max(
      CROP_MIN_PERCENT,
      (CROP_MIN_DISPLAY_PIXELS / Math.max(1, drag.rect.width)) * 100,
    ),
    minHeight = Math.max(
      CROP_MIN_PERCENT,
      (CROP_MIN_DISPLAY_PIXELS / Math.max(1, drag.rect.height)) * 100,
    );
  if (drag.mode.includes('w'))
    left = clampCropValue(start.x + dx, 0, right - minWidth);
  if (drag.mode.includes('e'))
    right = clampCropValue(start.x + start.width + dx, left + minWidth, 100);
  if (drag.mode.includes('n'))
    top = clampCropValue(start.y + dy, 0, bottom - minHeight);
  if (drag.mode.includes('s'))
    bottom = clampCropValue(start.y + start.height + dy, top + minHeight, 100);
  setCropValues({ x: left, y: top, width: right - left, height: bottom - top });
  if (activeImageEditor) activeImageEditor.cropDirty = true;
}

function endCropPointer(event) {
  let drag = activeCropPointer;
  if (!drag || event.pointerId !== drag.pointerId) return;
  try {
    drag.target?.releasePointerCapture(event.pointerId);
  } catch (error) {}
  activeCropPointer = null;
  $('#image-crop-overlay')?.classList.remove('dragging');
  document.body.classList.remove('crop-pointer-active');
}

document.addEventListener('pointermove', moveCropPointer, { passive: false });
document.addEventListener('pointerup', endCropPointer);
document.addEventListener('pointercancel', endCropPointer);

function copyEditorCanvas() {
  let source = $('#image-editor-canvas'),
    copy = document.createElement('canvas');
  copy.width = source.width;
  copy.height = source.height;
  copy.getContext('2d').drawImage(source, 0, 0);
  return copy;
}

function resetCropControls() {
  ['crop-x', 'crop-y'].forEach((id) => {
    let input = $(`#${id}`);
    if (input) input.value = '10';
  });
  ['crop-width', 'crop-height'].forEach((id) => {
    let input = $(`#${id}`);
    if (input) input.value = '80';
  });
  if (activeImageEditor) activeImageEditor.cropDirty = false;
  updateCropOverlay(false);
}

function cropEditorImage(showMessage = true) {
  let canvas = $('#image-editor-canvas');
  if (!canvas || !canvas.width) return;
  let source = copyEditorCanvas(),
    values = cropValues(),
    sx = Math.min(
      source.width - 1,
      Math.max(0, Math.round((source.width * values.x) / 100)),
    ),
    sy = Math.min(
      source.height - 1,
      Math.max(0, Math.round((source.height * values.y) / 100)),
    ),
    ex = Math.min(
      source.width,
      Math.max(
        sx + 1,
        Math.round((source.width * (values.x + values.width)) / 100),
      ),
    ),
    ey = Math.min(
      source.height,
      Math.max(
        sy + 1,
        Math.round((source.height * (values.y + values.height)) / 100),
      ),
    ),
    sw = ex - sx,
    sh = ey - sy;
  canvas.width = sw;
  canvas.height = sh;
  canvas.getContext('2d').drawImage(source, sx, sy, sw, sh, 0, 0, sw, sh);
  resetCropControls();
  if (showMessage) setImageEditorMessage(`Cropped to ${sw} × ${sh} pixels.`);
  return { width: sw, height: sh };
}

function transformEditorImage(operation) {
  let canvas = $('#image-editor-canvas');
  if (!canvas || !canvas.width) return;
  let source = copyEditorCanvas(),
    context;
  if (operation === 'left' || operation === 'right') {
    canvas.width = source.height;
    canvas.height = source.width;
    context = canvas.getContext('2d');
    if (operation === 'right') {
      context.translate(canvas.width, 0);
      context.rotate(Math.PI / 2);
    } else {
      context.translate(0, canvas.height);
      context.rotate(-Math.PI / 2);
    }
    context.drawImage(source, 0, 0);
  } else {
    canvas.width = source.width;
    canvas.height = source.height;
    context = canvas.getContext('2d');
    if (operation === 'horizontal') {
      context.translate(canvas.width, 0);
      context.scale(-1, 1);
    } else {
      context.translate(0, canvas.height);
      context.scale(1, -1);
    }
    context.drawImage(source, 0, 0);
  }
  updateCropOverlay(false);
  setImageEditorMessage(
    operation === 'horizontal'
      ? 'Flipped horizontally.'
      : operation === 'vertical'
        ? 'Flipped vertically.'
        : `Rotated ${operation} 90 degrees.`,
  );
}

function resetEditorImage() {
  if (!activeImageEditor) return;
  let image = activeImageEditor.resetImage;
  if (!image.complete || !image.naturalWidth) {
    setImageEditorMessage('Preparing the original selected image…');
    image.addEventListener('load', resetEditorImage, { once: true });
    return;
  }
  let canvas = $('#image-editor-canvas'),
    maxDimension = 4096,
    scale = Math.min(
      1,
      maxDimension / Math.max(image.naturalWidth, image.naturalHeight),
    );
  canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
  canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
  canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
  resetCropControls();
  setImageEditorMessage('Edits reset to the original selected image.');
}

function editorOutputName(name, mimeType) {
  let base =
      String(name || 'image')
        .replace(/\.[^.]+$/, '')
        .replace(/[^A-Za-z0-9_-]/g, '_')
        .slice(0, 100) || 'image',
    extension =
      mimeType === 'image/png'
        ? 'png'
        : mimeType === 'image/webp'
          ? 'webp'
          : 'jpg';
  return `${base}-edited.${extension}`;
}

function editedImageFile(blob, filename, mimeType) {
  return new File([blob], filename, {
    type: mimeType,
    lastModified: Date.now(),
  });
}

function replaceInputUploadFile(inputId, file) {
  let input = $(`#${inputId}`);
  if (!input || typeof DataTransfer === 'undefined') return;
  try {
    let transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
  } catch (error) {}
}

function applyImageEdit() {
  if (!activeImageEditor) return;
  let canvas = $('#image-editor-canvas');
  if (activeImageEditor.cropDirty) cropEditorImage(false);
  let editor = { ...activeImageEditor },
    mimeType = IMAGE_EDITOR_TYPES.includes(editor.mimeType)
      ? editor.mimeType
      : 'image/jpeg';
  canvas.toBlob(
    (blob) => {
      if (!blob) {
        setImageEditorMessage('The edited image could not be prepared.');
        return;
      }
      if (blob.size < 1 || blob.size > IMAGE_EDITOR_MAX_BYTES) {
        setImageEditorMessage(
          'The edited image must be no larger than 5 MB. Try a smaller crop or JPEG image.',
        );
        return;
      }
      let filename = editorOutputName(editor.originalName, mimeType),
        uploadFile = editedImageFile(blob, filename, mimeType),
        previewUrl = URL.createObjectURL(uploadFile);
      if (editor.batchToken) {
        let batch = editedImageBatches.get(editor.inputId),
          item = batch?.items?.find(
            (candidate) => candidate.token === editor.batchToken,
          );
        if (!item) {
          URL.revokeObjectURL(previewUrl);
          closeImageEditor();
          return;
        }
        if (item.previewUrl && item.previewUrl !== item.sourceUrl)
          URL.revokeObjectURL(item.previewUrl);
        Object.assign(item, {
          uploadFile,
          filename,
          mimeType,
          previewUrl,
          applied: true,
          width: canvas.width,
          height: canvas.height,
        });
        closeImageEditor();
        renderImageBatchQueue(editor.inputId);
        return;
      }
      let preview = $(`#${editor.previewId}`),
        previous = editedImageSelections.get(editor.inputId);
      if (previous?.previewUrl) URL.revokeObjectURL(previous.previewUrl);
      editedImageSelections.set(editor.inputId, {
        ...previous,
        sourceFile: previous?.sourceFile || editor.resetFile,
        uploadFile,
        filename,
        mimeType,
        previewUrl,
        applied: true,
        width: canvas.width,
        height: canvas.height,
      });
      replaceInputUploadFile(editor.inputId, uploadFile);
      if (preview)
        preview.innerHTML = `<img src="${previewUrl}" alt="Edited image preview"><small>Ready (edited) · ${canvas.width} × ${canvas.height}</small>`;
      if (typeof editor.onApply === 'function') editor.onApply(uploadFile);
      closeImageEditor();
    },
    mimeType,
    mimeType === 'image/jpeg' ? 0.9 : undefined,
  );
}

function releaseEditedImageSelection(selection) {
  if (selection?.sourceUrl) URL.revokeObjectURL(selection.sourceUrl);
  if (selection?.previewUrl && selection.previewUrl !== selection.sourceUrl)
    URL.revokeObjectURL(selection.previewUrl);
}

function removeEditedImage(inputId, previewId) {
  let selection = editedImageSelections.get(inputId);
  releaseEditedImageSelection(selection);
  editedImageSelections.delete(inputId);
  let input = $(`#${inputId}`),
    preview = $(`#${previewId}`);
  if (input) input.value = '';
  if (preview) preview.innerHTML = '<span>No image selected</span>';
}

function appendEditedImage(formData, inputId) {
  let selection = editedImageSelections.get(inputId),
    file = selection?.uploadFile || selection?.sourceFile;
  if (file) formData.append('image', file, file.name || selection.filename);
  return Boolean(file);
}

function clearEditedImageSelection(inputId) {
  let selection = editedImageSelections.get(inputId);
  releaseEditedImageSelection(selection);
  editedImageSelections.delete(inputId);
}
