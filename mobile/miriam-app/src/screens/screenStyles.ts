import { StyleSheet } from 'react-native';

export const screenStyles = StyleSheet.create({
  page: {
    flex: 1,
    padding: 18,
  },
  headerRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: 12,
    marginBottom: 14,
  },
  title: {
    color: '#16201f',
    fontSize: 28,
    fontWeight: '800',
  },
  subtitle: {
    color: '#65706d',
    fontSize: 14,
    marginTop: 4,
  },
  card: {
    backgroundColor: '#fff',
    borderRadius: 8,
    padding: 14,
    marginBottom: 12,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#dfe6e3',
  },
  cardTitle: {
    color: '#16201f',
    fontSize: 17,
    fontWeight: '800',
    marginBottom: 8,
  },
  body: {
    color: '#263936',
    fontSize: 15,
    lineHeight: 21,
  },
  muted: {
    color: '#64726f',
    fontSize: 13,
  },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    alignItems: 'center',
  },
  empty: {
    color: '#60706b',
    paddingVertical: 12,
  },
  input: {
    minHeight: 48,
    backgroundColor: '#fff',
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#cfd8d4',
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 16,
  },
});
