module.exports = {
  devServer: {
    disableHostCheck: true,
    host: '0.0.0.0',
    port: 8088
  },
  publicPath: process.env.NODE_ENV === 'production' ? '/Go3.N/' : '/'
}
