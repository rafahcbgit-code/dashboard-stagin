export const decodeMetaUnicode = (value) => {
    if (typeof value !== 'string') {
        return value;
    }

    return value.replace(/\\u([0-9A-Fa-f]{4})/g, (_, hex) =>
        String.fromCharCode(parseInt(hex, 16))
    );
};
