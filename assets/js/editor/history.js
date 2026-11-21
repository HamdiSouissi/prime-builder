export function createHistory(initialState, limit = 100) {
  let stack = [structuredClone(initialState)];
  let index = 0;

  return {
    push(state) {
      stack = stack.slice(0, index + 1);
      stack.push(structuredClone(state));
      if (stack.length > limit) {
        stack.shift();
      } else {
        index++;
      }
    },
    canUndo() {
      return index > 0;
    },
    canRedo() {
      return index < stack.length - 1;
    },
    undo() {
      if (!this.canUndo()) return null;
      index--;
      return structuredClone(stack[index]);
    },
    redo() {
      if (!this.canRedo()) return null;
      index++;
      return structuredClone(stack[index]);
    },
    current() {
      return structuredClone(stack[index]);
    },
  };
}
