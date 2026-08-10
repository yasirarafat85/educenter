package com.yasirarafat.clipnotes.ui.screens

import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.lazy.LazyItemScope
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.zIndex

/**
 * Minimal, dependency-free drag-to-reorder for a LazyColumn.
 *
 * Long-press anywhere in the list (via [Modifier.dragContainer]) picks up the
 * item under the finger; dragging moves it and calls [onMove] each time it
 * crosses a neighbour (reorder your own list there so it animates live);
 * [onDrop] fires once when the finger lifts (persist the new order there).
 *
 * Auto-scroll during drag is intentionally omitted to keep the behaviour simple
 * and predictable — the user drags within the visible area.
 */
class DragDropState internal constructor(
    private val listState: LazyListState,
    private val onMove: (Int, Int) -> Unit,
    private val onDrop: () -> Unit
) {
    var draggingItemIndex by mutableStateOf<Int?>(null)
        private set
    var draggingItemOffset by mutableStateOf(0f)
        private set

    private val currentItemInfo
        get() = draggingItemIndex?.let { idx ->
            listState.layoutInfo.visibleItemsInfo.firstOrNull { it.index == idx }
        }

    internal fun onDragStart(offsetY: Float) {
        listState.layoutInfo.visibleItemsInfo
            .firstOrNull { offsetY.toInt() in it.offset..(it.offset + it.size) }
            ?.let {
                draggingItemIndex = it.index
                draggingItemOffset = 0f
            }
    }

    internal fun onDrag(deltaY: Float) {
        val index = draggingItemIndex ?: return
        draggingItemOffset += deltaY
        val current = currentItemInfo ?: return
        val middleOfDragged = current.offset + draggingItemOffset + current.size / 2f
        val target = listState.layoutInfo.visibleItemsInfo.firstOrNull {
            it.index != index && middleOfDragged.toInt() in it.offset..(it.offset + it.size)
        } ?: return
        onMove(index, target.index)
        // Keep the dragged card visually under the finger after the list shifts.
        draggingItemOffset += current.offset - target.offset
        draggingItemIndex = target.index
    }

    internal fun onDragEnd() {
        if (draggingItemIndex != null) onDrop()
        draggingItemIndex = null
        draggingItemOffset = 0f
    }
}

@Composable
fun rememberDragDropState(
    listState: LazyListState,
    onMove: (Int, Int) -> Unit,
    onDrop: () -> Unit
): DragDropState = remember(listState) { DragDropState(listState, onMove, onDrop) }

/** Attach to the LazyColumn to capture long-press drags. */
fun Modifier.dragContainer(state: DragDropState): Modifier = pointerInput(state) {
    detectDragGesturesAfterLongPress(
        onDragStart = { offset -> state.onDragStart(offset.y) },
        onDrag = { change, amount -> change.consume(); state.onDrag(amount.y) },
        onDragEnd = { state.onDragEnd() },
        onDragCancel = { state.onDragEnd() }
    )
}

/** Wrap each LazyColumn item so the one being dragged floats above the rest. */
@Composable
fun LazyItemScope.DraggableItem(
    state: DragDropState,
    index: Int,
    content: @Composable (isDragging: Boolean) -> Unit
) {
    val dragging = index == state.draggingItemIndex
    val modifier = if (dragging) {
        Modifier.zIndex(1f).graphicsLayer { translationY = state.draggingItemOffset }
    } else {
        Modifier
    }
    Box(modifier) { content(dragging) }
}
